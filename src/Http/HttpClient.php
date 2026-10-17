<?php

declare(strict_types=1);

namespace SealGate\Http;

use SealGate\Exception\ApiException;
use SealGate\Exception\AuthenticationException;
use SealGate\Exception\ChallengeExpiredException;
use SealGate\Exception\ConnectionException;
use SealGate\Exception\NotFoundException;
use SealGate\Exception\PaymentRequiredException;
use SealGate\Exception\PermissionException;
use SealGate\Exception\RateLimitException;
use SealGate\Exception\SealGateException;
use SealGate\Exception\ValidationException;
use SealGate\Exception\VerificationException;
use SealGate\Version;

/**
 * Calls `/v1`: retries (429, 5xx, connection errors), timeouts and error mapping.
 *
 * @internal
 */
final class HttpClient
{
    /** Longest wait honoured for 429, in seconds (the rate limit window is one minute). */
    public const MAX_RATE_LIMIT_WAIT = 60.0;
    private const BACKOFF_BASE = 0.5;
    private const BACKOFF_MAX = 8.0;

    /** @var \Closure(float): void */
    public \Closure $sleep;
    /** @var \Closure(): float */
    public \Closure $random;
    /** @var \Closure(): float */
    public \Closure $clock;

    public function __construct(
        private readonly string $apiKey,
        public readonly string $baseUrl,
        private readonly float $timeout,
        private readonly int $maxRetries,
        private readonly Transport $transport,
    ) {
        $this->sleep = static function (float $s): void {
            usleep((int) round($s * 1_000_000));
        };
        $this->random = static fn (): float => mt_rand() / mt_getrandmax();
        $this->clock = static fn (): float => microtime(true);
    }

    /** Seconds: exponential backoff with ±25% jitter. `$attempt` starts at 0. */
    public static function backoff(int $attempt, callable $random): float
    {
        $base = min(self::BACKOFF_MAX, self::BACKOFF_BASE * (2 ** $attempt));
        return round($base * (0.75 + $random() * 0.5), 3);
    }

    /** Seconds from `retry-after` (seconds or HTTP date). */
    public static function parseRetryAfter(?string $value, float $now): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }
        $at = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \\G\\M\\T', $value, new \DateTimeZone('UTC'));
        if ($at === false) {
            return null;
        }
        return max(0.0, (float) $at->getTimestamp() - $now);
    }

    /**
     * @param array<string, scalar|null> $query `null` is left out.
     * @param array{timeout?: float|int|null, max_retries?: int|null} $options
     * @param bool $idempotent Safe to send twice (GET, PATCH, DELETE). 5xx, timeouts and connection errors are
     *                         retried only for these. 429 is always retried: the API rejects it before doing anything.
     */
    public function request(
        string $method,
        string $path,
        bool $idempotent,
        array $query = [],
        mixed $body = null,
        bool $hasBody = false,
        array $options = [],
    ): mixed {
        $retries = $options['max_retries'] ?? $this->maxRetries;
        $timeout = (float) ($options['timeout'] ?? $this->timeout);
        $payload = null;
        if ($hasBody) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }
        for ($attempt = 0; ; $attempt++) {
            $o = $this->once($method, $path, $query, $payload, $timeout, $idempotent);
            if ($o->error === null) {
                return $o->value;
            }
            if (!$o->retryable || $attempt >= $retries) {
                throw $o->error;
            }
            ($this->sleep)($o->wait ?? self::backoff($attempt, $this->random));
        }
    }

    /** @param array<string, scalar|null> $query `null` is left out. */
    public function url(string $path, array $query = []): string
    {
        $pairs = [];
        foreach ($query as $k => $v) {
            if ($v !== null) {
                $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode(is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
            }
        }
        $qs = implode('&', $pairs);
        return $this->baseUrl . $path . ($qs === '' ? '' : '?' . $qs);
    }

    /** @param array<string, scalar|null> $query */
    private function once(string $method, string $path, array $query, ?string $payload, float $timeout, bool $idempotent): Outcome
    {
        $headers = [
            'authorization' => 'Bearer ' . $this->apiKey,
            'accept' => 'application/json',
            'user-agent' => Version::USER_AGENT,
        ];
        if ($payload !== null) {
            $headers['content-type'] = 'application/json';
        }
        try {
            $res = $this->transport->send(new Request($method, $this->url($path, $query), $headers, $payload, $timeout));
        } catch (ConnectionException $e) {
            return Outcome::fail($e, $idempotent);
        } catch (SealGateException $e) {
            return Outcome::fail($e, false);
        } catch (\Throwable $e) {
            return Outcome::fail(new ConnectionException('Connection error: ' . $e->getMessage(), previous: $e), $idempotent);
        }

        if ($res->status >= 200 && $res->status < 300) {
            if ($res->body === '') {
                return Outcome::ok(null);
            }
            try {
                return Outcome::ok(json_decode($res->body, true, 512, JSON_THROW_ON_ERROR));
            } catch (\JsonException $e) {
                $err = new ApiException(sprintf('Invalid JSON in response (HTTP %d)', $res->status), $res->status, null, $e);
                return Outcome::fail($err, false);
            }
        }
        $now = ($this->clock)();
        $error = self::errorFromResponse($res, $now);
        if ($res->status === 429) {
            $wait = self::parseRetryAfter($res->header('retry-after'), $now);
            if ($wait !== null && $wait > self::MAX_RATE_LIMIT_WAIT) {
                return Outcome::fail($error, false);
            }
            return Outcome::fail($error, true, $wait);
        }
        return Outcome::fail($error, $res->status >= 500 && $idempotent);
    }

    /** Builds the exception for a non-2xx response (`{ "error": { "code", "message" } }`). */
    public static function errorFromResponse(Response $res, float $now): SealGateException
    {
        try {
            $parsed = json_decode($res->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $parsed = null; // HTML from a proxy, empty body, etc.
        }
        $err = is_array($parsed) && isset($parsed['error']) && is_array($parsed['error']) ? $parsed['error'] : [];
        $code = isset($err['code']) && is_string($err['code']) ? $err['code'] : null;
        $message = isset($err['message']) && is_string($err['message']) ? $err['message'] : 'HTTP ' . $res->status;
        $args = [$message, $res->status, $code];
        return match (true) {
            $res->status === 400 => new ValidationException(...$args),
            $res->status === 401 && $code === 'verification_failed' => new VerificationException(...$args),
            $res->status === 401 => new AuthenticationException(...$args),
            $res->status === 402 => new PaymentRequiredException(...$args),
            $res->status === 403 => new PermissionException(...$args),
            $res->status === 404 => new NotFoundException(...$args),
            $res->status === 410 => new ChallengeExpiredException(...$args),
            $res->status === 429 => new RateLimitException($message, $res->status, $code, null, self::parseRetryAfter($res->header('retry-after'), $now)),
            default => new ApiException(...$args),
        };
    }
}
