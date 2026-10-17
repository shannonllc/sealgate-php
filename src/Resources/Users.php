<?php

declare(strict_types=1);

namespace SealGate\Resources;

/** Users by your `external_id` (`/v1/users/:external_id`). */
final class Users extends Resource
{
    /**
     * Lists the user's passkeys, revoked ones included (`revoked_at`).
     *
     * @param array{timeout?: float|int, max_retries?: int} $options
     * @return array{end_user_id: string, data: list<array<string, mixed>>}
     */
    public function listCredentials(string $externalId, array $options = []): array
    {
        return $this->http->request('GET', '/v1/users/' . self::id($externalId) . '/credentials', true, options: self::opts($options));
    }

    /**
     * Renames one of the user's passkeys (1 to 64 characters). 404 when it is revoked or belongs to another user.
     *
     * @param array{name: string} $params
     * @param array{timeout?: float|int, max_retries?: int} $options
     * @return array<string, mixed>
     */
    public function renameCredential(string $externalId, string $credentialId, array $params, array $options = []): array
    {
        $path = '/v1/users/' . self::id($externalId) . '/credentials/' . self::id($credentialId);
        return $this->http->request('PATCH', $path, true, body: self::pick($params, ['name']), hasBody: true, options: self::opts($options));
    }

    /**
     * Revokes all of the user's passkeys. The user and the audit log stay. Not retried on a 5xx or a connection error.
     *
     * @param array{timeout?: float|int, max_retries?: int} $options
     * @return array{end_user_id: string, revoked: int}
     */
    public function revoke(string $externalId, array $options = []): array
    {
        return $this->http->request('POST', '/v1/users/' . self::id($externalId) . '/revoke', false, options: self::opts($options));
    }

    /**
     * Deletes the user and their passkeys. Cannot be undone; registering the same `external_id` again makes a new user.
     *
     * @param array{timeout?: float|int, max_retries?: int} $options
     * @return array{end_user_id: string, deleted: bool, credentials_deleted: int}
     */
    public function delete(string $externalId, array $options = []): array
    {
        return $this->http->request('DELETE', '/v1/users/' . self::id($externalId), true, options: self::opts($options));
    }
}
