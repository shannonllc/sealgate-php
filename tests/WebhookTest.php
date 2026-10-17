<?php

declare(strict_types=1);

namespace SealGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SealGate\EventType;
use SealGate\Exception\SealGateException;
use SealGate\Exception\WebhookVerificationException;
use SealGate\Webhook;
use SealGate\WebhookEvent;

use function SealGate\verifyWebhook;

/**
 * Receiver-side verification of webhooks. The vectors are signed like SealGate, by hand and by the Node SDK, with
 * results decided by the Node SDK.
 */
final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
    private const OTHER = 'whsec_dGhpcyBpcyBhbm90aGVyIHNlY3JldCBrZXk=';

    /** @return list<array<string, mixed>> */
    private static function vectors(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/testdata/webhook_vectors.json'), true)['vectors'];
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function vectorCases(): array
    {
        $out = [];
        foreach (self::vectors() as $v) {
            $out[$v['name']] = [$v];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function find(string $name): array
    {
        foreach (self::vectors() as $v) {
            if ($v['name'] === $name) {
                return $v;
            }
        }
        throw new \LogicException($name);
    }

    /** @param array<string, mixed> $v */
    private static function at(array $v): FixedClockWebhook
    {
        return new FixedClockWebhook($v['secret'], $v['now'], $v['tolerance'] ?? 300);
    }

    /** @param array<string, mixed> $v */
    #[DataProvider('vectorCases')]
    public function testVectors(array $v): void
    {
        $wh = self::at($v);
        $run = match ($v['verify'] ?? null) {
            'webhook' => fn () => $wh->verifyEvent($v['payload'], $v['headers'])->raw,
            default => fn () => $wh->verify($v['payload'], $v['headers']),
        };
        if ($v['expect'] === null) {
            try {
                $run();
                self::fail('verified');
            } catch (WebhookVerificationException $e) {
                self::assertSame($v['error'], $e->getMessage());
            }
            return;
        }
        self::assertEquals($v['expect'], $run());
    }

    public function testSignMatchesTheSenderAndNode(): void
    {
        foreach (['signed like SealGate', 'signed with an HMAC by hand', 'signed by the node sdk', 'standard webhooks spec vector'] as $name) {
            $v = self::find($name);
            $h = $v['headers'];
            self::assertSame($h['webhook-signature'], (new Webhook([$v['secret'], self::OTHER]))->sign($h['webhook-id'], (int) $h['webhook-timestamp'], $v['payload']));
        }
        $v = self::find('signed like SealGate');
        $h = $v['headers'];
        $date = (new \DateTimeImmutable())->setTimestamp((int) $h['webhook-timestamp']);
        self::assertSame($h['webhook-signature'], (new Webhook(self::SECRET))->sign($h['webhook-id'], $date, $v['payload']));
    }

    public function testEventObject(): void
    {
        $v = self::find('webhook event signed like SealGate');
        $e = self::at($v)->verifyEvent($v['payload'], $v['headers']);
        self::assertInstanceOf(WebhookEvent::class, $e);
        self::assertSame([EventType::LOGIN_SUCCEEDED, '2026-09-22T00:00:00.000Z', 'app_1', '山田-123'], [$e->type, $e->timestamp, $e->data['app_id'], $e->data['external_id']]);
        self::assertSame($e->data, $e->raw['data']);
        self::assertNull((new WebhookEvent(['type' => 'user.revoked', 'data' => []]))->timestamp);
    }

    public function testFunctionWithTheRealClock(): void
    {
        $wh = new Webhook(self::SECRET);
        $now = time();
        $sign = fn (string $body) => ['webhook-id' => 'msg_now', 'webhook-timestamp' => (string) $now, 'webhook-signature' => $wh->sign('msg_now', $now, $body)];
        $ev = '{"type":"user.revoked","timestamp":"t","data":{"app_id":"app_1","end_user_id":"eu_1","revoked":2}}';
        self::assertSame(2, verifyWebhook($ev, $sign($ev), [self::OTHER, self::SECRET], 10)->data['revoked']);
        $this->expectException(WebhookVerificationException::class);
        verifyWebhook($ev, $sign($ev), self::OTHER);
    }

    public function testHeaderForms(): void
    {
        $v = self::find('signed like SealGate');
        $wh = self::at($v);
        $h = $v['headers'];
        $upper = ['Webhook-Id' => $h['webhook-id'], 'WEBHOOK-TIMESTAMP' => [$h['webhook-timestamp'], 'x'], 'webhook-signature' => $h['webhook-signature']];
        self::assertEquals($v['expect'], $wh->verify($v['payload'], $upper));
        $server = ['HTTP_WEBHOOK_ID' => $h['webhook-id'], 'HTTP_WEBHOOK_TIMESTAMP' => $h['webhook-timestamp'], 'HTTP_WEBHOOK_SIGNATURE' => $h['webhook-signature'], 'REQUEST_METHOD' => 'POST'];
        self::assertEquals($v['expect'], $wh->verify($v['payload'], $server));
        $psr7 = new class ($h) {
            /** @param array<string, string> $h */
            public function __construct(private array $h)
            {
            }

            public function getHeaderLine(string $name): string
            {
                return $this->h[strtolower($name)] ?? '';
            }
        };
        self::assertEquals($v['expect'], $wh->verify($v['payload'], $psr7));
        foreach ([[...$h, 'webhook-id' => []], [...$h, 'webhook-id' => null], new class () {
            public function getHeaderLine(string $name): string
            {
                return '';
            }
        }] as $bad) {
            try {
                $wh->verify($v['payload'], $bad);
                self::fail('verified');
            } catch (WebhookVerificationException $e) {
                self::assertSame('Missing required headers', $e->getMessage());
            }
        }
    }

    public function testNotJsonKeepsCause(): void
    {
        $v = self::find('signed body that is not JSON');
        try {
            self::at($v)->verify($v['payload'], $v['headers']);
            self::fail('verified');
        } catch (WebhookVerificationException $e) {
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }

    public function testDefaultToleranceAndClock(): void
    {
        $v = self::find('signed like SealGate');
        $wh = new Webhook(self::SECRET);
        self::assertSame(300, $wh->tolerance);
        $this->expectExceptionMessage('Message timestamp too old');
        $wh->verify($v['payload'], $v['headers']); // signed in 2026-09, checked with the real clock (later)
    }

    public function testSecretChecks(): void
    {
        foreach (['', [], [self::SECRET, ''], [self::SECRET, 5]] as $secret) {
            try {
                new Webhook($secret);
                self::fail('accepted');
            } catch (SealGateException $e) {
                self::assertNotInstanceOf(WebhookVerificationException::class, $e);
                self::assertSame('Webhook secret is required', $e->getMessage());
            }
        }
        try {
            new Webhook('whsec_***');
            self::fail('accepted');
        } catch (SealGateException $e) {
            self::assertStringContainsString('not valid base64', $e->getMessage());
        }
        // unpadded base64 is accepted, like atob in the Node SDK
        self::assertSame(300, (new Webhook('whsec_dGhpcyBpcyBhbm90aGVyIHNlY3JldCBrZXk'))->tolerance);
    }
}
