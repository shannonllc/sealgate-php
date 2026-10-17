<?php

declare(strict_types=1);

namespace SealGate\Exception;

/** 429 `rate_limited`: too many requests. Thrown after retries are used up. */
class RateLimitException extends SealGateException
{
    /** @param float|null $retryAfter Seconds to wait before retrying, from `retry-after`. */
    public function __construct(
        string $message,
        ?int $status = null,
        ?string $errorCode = null,
        ?\Throwable $previous = null,
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message, $status, $errorCode, $previous);
    }
}
