<?php

declare(strict_types=1);

namespace SealGate\Resources;

/** The API key's app (environment), by its ID `app_...` (`/v1/apps/:id`). Another app's ID is 404. */
final class Apps extends Resource
{
    /**
     * @param array{timeout?: float|int, max_retries?: int} $options
     * @return array<string, mixed>
     */
    public function get(string $appId, array $options = []): array
    {
        return $this->http->request('GET', '/v1/apps/' . self::id($appId), true, options: self::opts($options));
    }

    /**
     * Changes `resident_key` and `user_verification` (the only settings an API key can change; pass one or both).
     *
     * @param array{resident_key?: 'required'|'preferred'|'discouraged', user_verification?: 'required'|'preferred'} $params
     * @param array{timeout?: float|int, max_retries?: int} $options
     * @return array<string, mixed>
     */
    public function update(string $appId, array $params, array $options = []): array
    {
        $body = self::object(self::pick($params, ['resident_key', 'user_verification']));
        return $this->http->request('PATCH', '/v1/apps/' . self::id($appId), true, body: $body, hasBody: true, options: self::opts($options));
    }

    /**
     * Lists audit log entries, newest first (default 200, at most 1000). The API returns one list (no further pages);
     * narrow it with `since` (a `DateTimeInterface` or an ISO 8601 string) and `limit`.
     *
     * @param array{event?: string, since?: \DateTimeInterface|string, limit?: int} $params
     * @param array{timeout?: float|int, max_retries?: int} $options
     * @return array{data: list<array<string, mixed>>}
     */
    public function audit(string $appId, array $params = [], array $options = []): array
    {
        $since = $params['since'] ?? null;
        if ($since instanceof \DateTimeInterface) {
            $since = \DateTimeImmutable::createFromInterface($since)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        $query = ['event' => $params['event'] ?? null, 'since' => $since, 'limit' => $params['limit'] ?? null];
        return $this->http->request('GET', '/v1/apps/' . self::id($appId) . '/audit', true, query: $query, options: self::opts($options));
    }

    /**
     * MAU and registrations for the last 6 months, logins for the last 30 days, users and transports.
     *
     * @param array{timeout?: float|int, max_retries?: int} $options
     * @return array<string, mixed>
     */
    public function stats(string $appId, array $options = []): array
    {
        return $this->http->request('GET', '/v1/apps/' . self::id($appId) . '/stats', true, options: self::opts($options));
    }
}
