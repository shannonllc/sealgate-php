<?php

declare(strict_types=1);

namespace SealGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SealGate\Client;
use SealGate\Exception\ApiException;
use SealGate\Exception\AuthenticationException;
use SealGate\Exception\ChallengeExpiredException;
use SealGate\Exception\ConnectionException;
use SealGate\Exception\NotFoundException;
use SealGate\Exception\PaymentRequiredException;
use SealGate\Exception\PermissionException;
use SealGate\Exception\RateLimitException;
use SealGate\Exception\SealGateException;
use SealGate\Exception\TimeoutException;
use SealGate\Exception\ValidationException;
use SealGate\Exception\VerificationException;
use SealGate\Http\HttpClient;
use SealGate\Http\Response;
use SealGate\Version;

/** Retries, timeouts, error types and configuration. */
final class HttpTest extends TestCase
{
    private const APP = ['id' => 'app_1'];

    protected function tearDown(): void
    {
        putenv(Client::API_KEY_ENV);
    }

    /** @return array{0: ?\Throwable, 1: mixed} */
    private static function attempt(callable $fn): array
    {
        try {
            return [null, $fn()];
        } catch (\Throwable $e) {
            return [$e, null];
        }
    }

    private static function verify(Client $c): mixed
    {
        return $c->login->verify(['challenge_id' => 'c', 'response' => []]);
    }

    public function testHeaders(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, self::APP));
        FakeTransport::client($t)->apps->get('app_1');
        $h = $t->last()->headers;
        self::assertSame('Bearer sk_test_abc', $h['authorization']);
        self::assertSame('application/json', $h['accept']);
        self::assertSame('sealgate-php/' . Version::VERSION, $h['user-agent']);
        self::assertArrayNotHasKey('content-type', $h);
        self::assertNull($t->last()->body);
        self::assertSame(30.0, $t->last()->timeout);
    }

    public function testBodyAndUnicode(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, ['challenge_id' => 'c', 'options' => []]));
        FakeTransport::client($t)->register->options(['external_id' => 'u1', 'display_name' => '山田', 'unknown' => 'dropped']);
        self::assertSame('application/json', $t->last()->headers['content-type']);
        self::assertSame('{"external_id":"u1","display_name":"山田"}', $t->last()->body);
    }

    public function testUserAgentFormat(): void
    {
        self::assertMatchesRegularExpression('/^sealgate-php\/\d+\.\d+\.\d+$/', Version::USER_AGENT);
    }

    public function testVersionMatchesChangelog(): void
    {
        self::assertStringContainsString('## ' . Version::VERSION, (string) file_get_contents(__DIR__ . '/../CHANGELOG.md'));
    }

    public function testApiKeyFromEnv(): void
    {
        putenv(Client::API_KEY_ENV . '= sk_live_env ');
        $t = new FakeTransport(FakeTransport::json(200, self::APP));
        (new Client(null, ['transport' => $t]))->apps->get('app_1');
        self::assertSame('Bearer sk_live_env', $t->last()->headers['authorization']);
    }

    public function testMissingApiKey(): void
    {
        putenv(Client::API_KEY_ENV . '=  ');
        [$e] = self::attempt(fn () => new Client());
        self::assertInstanceOf(SealGateException::class, $e);
        self::assertStringContainsString(Client::API_KEY_ENV, $e->getMessage());
        [$e2] = self::attempt(fn () => new Client(''));
        self::assertInstanceOf(SealGateException::class, $e2);
    }

    public function testBaseUrlAndTimeout(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, self::APP));
        $c = new Client('sk_test_x', ['transport' => $t]);
        self::assertSame('https://api.sealgate.jp', $c->baseUrl);
        $c->apps->get('app_1');
        self::assertSame('https://api.sealgate.jp/v1/apps/app_1', $t->last()->url);
        self::assertSame('http://localhost:3003', (new Client('sk_test_x', ['base_url' => 'http://localhost:3003//']))->baseUrl);
        (new Client('sk_test_x', ['transport' => $t, 'timeout' => 5]))->apps->get('a');
        self::assertSame(5.0, $t->last()->timeout);
        (new Client('sk_test_x', ['transport' => $t, 'timeout' => 5]))->apps->get('a', ['timeout' => 1.5, 'unknown' => 1]);
        self::assertSame(1.5, $t->last()->timeout);
    }

    /** @return list<array{int, string, class-string}> */
    public static function statuses(): array
    {
        return [
            [400, 'invalid_request', ValidationException::class],
            [401, 'invalid_api_key', AuthenticationException::class],
            [401, 'verification_failed', VerificationException::class],
            [402, 'mau_limit', PaymentRequiredException::class],
            [403, 'credential_revoked', PermissionException::class],
            [403, 'recovery_not_allowed', PermissionException::class],
            [404, 'not_found', NotFoundException::class],
            [410, 'challenge_expired', ChallengeExpiredException::class],
            [418, 'teapot', ApiException::class],
        ];
    }

    #[DataProvider('statuses')]
    public function testStatusToClass(int $status, string $code, string $class): void
    {
        $t = new FakeTransport(FakeTransport::error($status, $code));
        [$e] = self::attempt(fn () => self::verify(FakeTransport::client($t)));
        self::assertInstanceOf($class, $e);
        self::assertInstanceOf(SealGateException::class, $e);
        self::assertSame([$status, $code, "m $status"], [$e->getStatus(), $e->getErrorCode(), $e->getMessage()]);
        self::assertSame([$status, $code], [$e->status, $e->errorCode]);
        self::assertCount(1, $t->calls);
    }

    public function testBodiesThatAreNotTheApiErrorShape(): void
    {
        $t = new FakeTransport(
            new Response(502, [], '<html>bad gateway</html>'),
            new Response(400, [], 'null'),
            new Response(401, [], '{"error":"invalid_api_key","message":"flat"}'),
            new Response(404, [], '{"error":{"code":1,"message":null}}'),
        );
        $c = FakeTransport::client($t, ['max_retries' => 0]);
        foreach ([[502, ApiException::class], [400, ValidationException::class], [401, AuthenticationException::class], [404, NotFoundException::class]] as [$status, $class]) {
            [$e] = self::attempt(fn () => $c->apps->get('a'));
            self::assertInstanceOf($class, $e);
            self::assertSame([$status, null, "HTTP $status"], [$e->status, $e->errorCode, $e->getMessage()]);
        }
    }

    public function testInvalidJsonIn2xx(): void
    {
        $t = new FakeTransport(new Response(200, [], '{oops'));
        [$e] = self::attempt(fn () => FakeTransport::client($t)->apps->get('a'));
        self::assertInstanceOf(ApiException::class, $e);
        self::assertSame(200, $e->status);
        self::assertStringContainsString('Invalid JSON', $e->getMessage());
        self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        self::assertCount(1, $t->calls);
    }

    public function testOtherTransportErrorsAreConnectionErrors(): void
    {
        $boom = new \RuntimeException('socket hang up');
        $t = new FakeTransport($boom);
        [$e] = self::attempt(fn () => FakeTransport::client($t, ['max_retries' => 0])->apps->get('a'));
        self::assertInstanceOf(ConnectionException::class, $e);
        self::assertNotInstanceOf(TimeoutException::class, $e);
        self::assertSame($boom, $e->getPrevious());
        self::assertSame('Connection error: socket hang up', $e->getMessage());
        self::assertNull($e->status);
    }

    public function testSdkExceptionsFromATransportAreNotWrapped(): void
    {
        $own = new PermissionException('custom');
        $t = new FakeTransport($own);
        [$e] = self::attempt(fn () => FakeTransport::client($t)->apps->get('a'));
        self::assertSame($own, $e);
        self::assertCount(1, $t->calls);
    }

    public function testConstructDirectly(): void
    {
        $e = new RateLimitException('slow');
        self::assertSame(['slow', null, null, null], [$e->getMessage(), $e->status, $e->retryAfter, $e->errorCode]);
        self::assertSame(0, $e->getCode());
    }

    public function test5xxWithBackoffThenSuccess(): void
    {
        $s = new Sleeps();
        $t = new FakeTransport(FakeTransport::error(500, 'internal_error'), FakeTransport::json(503, []), FakeTransport::json(200, self::APP));
        self::assertSame(self::APP, FakeTransport::client($t, [], $s)->apps->get('app_1'));
        self::assertCount(3, $t->calls);
        self::assertSame([0.5, 1.0], $s->waits);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $s = new Sleeps();
        $t = new FakeTransport(FakeTransport::error(500, 'internal_error'));
        [$e] = self::attempt(fn () => FakeTransport::client($t, ['max_retries' => 3], $s)->apps->get('a'));
        self::assertInstanceOf(ApiException::class, $e);
        self::assertCount(4, $t->calls);
        self::assertSame([0.5, 1.0, 2.0], $s->waits);
    }

    public function testMaxRetriesPerRequest(): void
    {
        $t = new FakeTransport(FakeTransport::json(500, []));
        self::attempt(fn () => FakeTransport::client($t, ['max_retries' => 5])->apps->get('a', ['max_retries' => 0]));
        self::assertCount(1, $t->calls);
    }

    public function testConnectionErrorsAndTimeoutsRetriedForSafeRequests(): void
    {
        $t = new FakeTransport(new \RuntimeException('ECONNRESET'), new TimeoutException('t'), FakeTransport::json(200, self::APP));
        self::assertSame(self::APP, FakeTransport::client($t)->apps->get('a'));
        self::assertCount(3, $t->calls);
        $t = new FakeTransport(new TimeoutException('Request timed out after 1 s'));
        [$e] = self::attempt(fn () => FakeTransport::client($t, ['max_retries' => 1])->apps->get('a'));
        self::assertInstanceOf(TimeoutException::class, $e);
        self::assertInstanceOf(ConnectionException::class, $e);
        self::assertCount(2, $t->calls);
    }

    public function testPatchAndDeleteAreRetried(): void
    {
        $t = new FakeTransport(FakeTransport::json(502, []), FakeTransport::json(200, ['id' => 'crd_1']));
        FakeTransport::client($t)->credentials->rename('crd_1', ['name' => 'x']);
        self::assertCount(2, $t->calls);
        $t = new FakeTransport(new ConnectionException('reset'), FakeTransport::json(200, ['credential_id' => 'crd_1']));
        FakeTransport::client($t)->credentials->revoke('crd_1');
        self::assertCount(2, $t->calls);
    }

    /** @return list<array{mixed}> */
    public static function unsafeFailures(): array
    {
        return [[FakeTransport::json(500, [])], [new \RuntimeException('ECONNRESET')], [new TimeoutException('t')]];
    }

    #[DataProvider('unsafeFailures')]
    public function testPostIsNotRetried(mixed $reply): void
    {
        $t = new FakeTransport($reply);
        $c = FakeTransport::client($t);
        $ops = [
            fn () => $c->register->options(['external_id' => 'u', 'display_name' => 'd']),
            fn () => $c->register->verify(['challenge_id' => 'c', 'response' => []]),
            fn () => $c->login->options(),
            fn () => self::verify($c),
            fn () => $c->users->revoke('u'),
            fn () => $c->recovery->start(['external_id' => 'u']),
        ];
        foreach ($ops as $op) {
            [$e] = self::attempt($op);
            self::assertInstanceOf(SealGateException::class, $e);
        }
        self::assertCount(count($ops), $t->calls);
    }

    public function test4xxNotRetried(): void
    {
        foreach ([400, 401, 402, 403, 404, 410] as $s) {
            $t = new FakeTransport(FakeTransport::error($s, 'x'));
            self::attempt(fn () => FakeTransport::client($t)->apps->get('a'));
            self::assertCount(1, $t->calls);
        }
    }

    public function test429WaitsRetryAfterForAnyRequest(): void
    {
        $s = new Sleeps();
        $t = new FakeTransport(FakeTransport::error(429, 'rate_limited', 'slow', ['retry-after' => '7']), FakeTransport::json(200, ['end_user_id' => 'eu_1']));
        self::assertSame(['end_user_id' => 'eu_1'], self::verify(FakeTransport::client($t, [], $s)));
        self::assertSame([7.0], $s->waits);
    }

    public function test429WithoutHeadersUsesBackoff(): void
    {
        $s = new Sleeps();
        $t = new FakeTransport(FakeTransport::error(429, 'rate_limited'), FakeTransport::json(200, self::APP));
        FakeTransport::client($t, [], $s)->apps->get('a');
        self::assertSame([0.5], $s->waits);
    }

    public function test429ThrownWithRetryAfter(): void
    {
        $t = new FakeTransport(FakeTransport::error(429, 'rate_limited', 'x', ['retry-after' => '1']));
        [$e] = self::attempt(fn () => FakeTransport::client($t, ['max_retries' => 1])->apps->get('a'));
        self::assertInstanceOf(RateLimitException::class, $e);
        self::assertSame([1.0, 'rate_limited'], [$e->retryAfter, $e->errorCode]);
        self::assertCount(2, $t->calls);
    }

    public function test429AtExactlyOneMinuteIsRetried(): void
    {
        $s = new Sleeps();
        $t = new FakeTransport(FakeTransport::json(429, [], ['retry-after' => '60']), FakeTransport::json(200, self::APP));
        FakeTransport::client($t, [], $s)->apps->get('a');
        self::assertSame([60.0], $s->waits);
    }

    public function test429LongerThanAMinuteThrownAtOnce(): void
    {
        $s = new Sleeps();
        $t = new FakeTransport(FakeTransport::error(429, 'rate_limited', 'x', ['retry-after' => '61']));
        [$e] = self::attempt(fn () => FakeTransport::client($t, [], $s)->apps->get('a'));
        self::assertInstanceOf(RateLimitException::class, $e);
        self::assertCount(1, $t->calls);
        self::assertSame([], $s->waits);
    }

    public function testDefaultSleepRandomAndClock(): void
    {
        $c = new Client('sk_test_x', ['transport' => new FakeTransport(FakeTransport::json(200, []))]);
        $start = microtime(true);
        ($c->http->sleep)(0.01);
        self::assertGreaterThanOrEqual(0.009, microtime(true) - $start);
        $r = ($c->http->random)();
        self::assertTrue($r >= 0 && $r <= 1);
        self::assertEqualsWithDelta(microtime(true), ($c->http->clock)(), 5);
    }

    public function testBackoff(): void
    {
        $half = static fn (): float => 0.5;
        self::assertSame([0.5, 1.0, 2.0, 4.0, 8.0, 8.0, 8.0], array_map(fn ($n) => HttpClient::backoff($n, $half), [0, 1, 2, 3, 4, 5, 10]));
        self::assertSame(0.375, HttpClient::backoff(0, static fn (): float => 0.0));
        self::assertSame(0.625, HttpClient::backoff(0, static fn (): float => 1.0));
    }

    public function testParseRetryAfter(): void
    {
        self::assertSame(2.0, HttpClient::parseRetryAfter('2', 0));
        self::assertSame(0.4, HttpClient::parseRetryAfter('0.4', 0));
        self::assertSame(4.0, HttpClient::parseRetryAfter('Thu, 01 Jan 1970 00:00:05 GMT', 1));
        self::assertSame(0.0, HttpClient::parseRetryAfter('Thu, 01 Jan 1970 00:00:05 GMT', 9));
        self::assertNull(HttpClient::parseRetryAfter('soon', 0));
        self::assertSame(0.0, HttpClient::parseRetryAfter('-3', 0));
        self::assertNull(HttpClient::parseRetryAfter(' ', 0));
        self::assertNull(HttpClient::parseRetryAfter(null, 0));
        self::assertSame(60.0, HttpClient::MAX_RATE_LIMIT_WAIT);
    }

    public function testEmptyResponseIsNull(): void
    {
        $t = new FakeTransport(new Response(200));
        self::assertNull(FakeTransport::client($t)->http->request('GET', '/v1/x', true));
    }

    public function testQueryValues(): void
    {
        $t = new FakeTransport(FakeTransport::json(200, []));
        $c = FakeTransport::client($t);
        self::assertSame('https://api.test/p?a=true&b=false&c=1&e=x%20y&f=', $c->http->url('/p', ['a' => true, 'b' => false, 'c' => 1, 'd' => null, 'e' => 'x y', 'f' => '']));
    }
}
