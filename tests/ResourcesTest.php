<?php

declare(strict_types=1);

namespace SealGate\Tests;

use PHPUnit\Framework\TestCase;
use SealGate\Client;

/** Each method: method, path, query and body. */
final class ResourcesTest extends TestCase
{
    private FakeTransport $t;
    private Client $c;

    protected function setUp(): void
    {
        $this->t = new FakeTransport(FakeTransport::json(200, ['ok' => true]));
        $this->c = FakeTransport::client($this->t);
    }

    /** @param array<string, mixed>|null $body */
    private function sent(string $method, string $path, ?array $body = null, ?string $raw = null): void
    {
        [$m, $p, $b] = $this->t->lastCall();
        self::assertSame([$method, $path, $body], [$m, $p, $b]);
        if ($raw !== null) {
            self::assertSame($raw, $this->t->last()->body);
        }
    }

    public function testRegister(): void
    {
        self::assertSame(['ok' => true], $this->c->register->options(['external_id' => 'u1', 'display_name' => 'taro', 'signed_in' => true]));
        $this->sent('POST', '/v1/register/options', ['external_id' => 'u1', 'display_name' => 'taro', 'signed_in' => true]);
        $this->c->register->options(['external_id' => 'u1', 'display_name' => 'taro', 'recovery_token' => 'rt_1']);
        $this->sent('POST', '/v1/register/options', ['external_id' => 'u1', 'display_name' => 'taro', 'recovery_token' => 'rt_1']);
        $this->c->register->verify(['challenge_id' => 'chl_1', 'response' => ['id' => 'x'], 'name' => 'iPhone']);
        $this->sent('POST', '/v1/register/verify', ['challenge_id' => 'chl_1', 'response' => ['id' => 'x'], 'name' => 'iPhone']);
    }

    public function testLogin(): void
    {
        $this->c->login->options();
        $this->sent('POST', '/v1/login/options', [], '{}');
        $this->c->login->options(['external_id' => 'u1']);
        $this->sent('POST', '/v1/login/options', ['external_id' => 'u1']);
        $this->c->login->verify(['challenge_id' => 'c', 'response' => ['id' => 'y']]);
        $this->sent('POST', '/v1/login/verify', ['challenge_id' => 'c', 'response' => ['id' => 'y']]);
    }

    public function testUsers(): void
    {
        $this->c->users->listCredentials('user/1 太郎');
        $this->sent('GET', '/v1/users/user%2F1%20%E5%A4%AA%E9%83%8E/credentials');
        $this->c->users->renameCredential('u1', 'crd_1', ['name' => 'Mac']);
        $this->sent('PATCH', '/v1/users/u1/credentials/crd_1', ['name' => 'Mac']);
        $this->c->users->revoke('u1');
        $this->sent('POST', '/v1/users/u1/revoke');
        $this->c->users->delete('u1');
        $this->sent('DELETE', '/v1/users/u1');
    }

    public function testCredentialsAndRecovery(): void
    {
        $this->c->credentials->rename('crd_1', ['name' => 'Pixel']);
        $this->sent('PATCH', '/v1/credentials/crd_1', ['name' => 'Pixel']);
        $this->c->credentials->revoke('crd_1');
        $this->sent('DELETE', '/v1/credentials/crd_1');
        $this->c->recovery->start(['external_id' => 'u1', 'reason' => 'checked']);
        $this->sent('POST', '/v1/recovery/start', ['external_id' => 'u1', 'reason' => 'checked']);
    }

    public function testApps(): void
    {
        $this->c->apps->get('app_1');
        $this->sent('GET', '/v1/apps/app_1');
        $this->c->apps->update('app_1', ['resident_key' => 'required']);
        $this->sent('PATCH', '/v1/apps/app_1', ['resident_key' => 'required']);
        $this->c->apps->update('app_1', []);
        $this->sent('PATCH', '/v1/apps/app_1', [], '{}');
        $this->c->apps->audit('app_1');
        $this->sent('GET', '/v1/apps/app_1/audit');
        $jst = new \DateTimeImmutable('2026-10-01 09:00:00', new \DateTimeZone('Asia/Tokyo'));
        $this->c->apps->audit('app_1', ['event' => 'login.failed', 'since' => $jst, 'limit' => 50]);
        $this->sent('GET', '/v1/apps/app_1/audit?event=login.failed&since=2026-10-01T00%3A00%3A00Z&limit=50');
        $this->c->apps->audit('app_1', ['since' => '2026-10-01']);
        $this->sent('GET', '/v1/apps/app_1/audit?since=2026-10-01');
        $this->c->apps->stats('app_1');
        $this->sent('GET', '/v1/apps/app_1/stats');
    }
}
