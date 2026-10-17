<?php

declare(strict_types=1);

namespace SealGate\Resources;

/** Passkeys by ID (`/v1/credentials/:id`). */
final class Credentials extends Resource
{
    /**
     * Renames a passkey (1 to 64 characters). 404 when it is revoked.
     *
     * @param array{name: string} $params
     * @param array{timeout?: float|int, max_retries?: int} $options
     * @return array<string, mixed>
     */
    public function rename(string $credentialId, array $params, array $options = []): array
    {
        return $this->http->request('PATCH', '/v1/credentials/' . self::id($credentialId), true, body: self::pick($params, ['name']), hasBody: true, options: self::opts($options));
    }

    /**
     * Revokes one passkey.
     *
     * @param array{timeout?: float|int, max_retries?: int} $options
     * @return array{credential_id: string, revoked_at: string|null}
     */
    public function revoke(string $credentialId, array $options = []): array
    {
        return $this->http->request('DELETE', '/v1/credentials/' . self::id($credentialId), true, options: self::opts($options));
    }
}
