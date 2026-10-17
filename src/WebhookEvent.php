<?php

declare(strict_types=1);

namespace SealGate;

/**
 * A webhook sent by SealGate (`{ type, timestamp, data }`). `data` always has `app_id`; the rest depends on `type`
 * (the `EventType` constants):
 *
 * | type | data |
 * |---|---|
 * | `register.completed` | `{ app_id, end_user_id, credential_id }` |
 * | `register.failed` | `{ app_id, code }` |
 * | `login.succeeded` | `{ app_id, end_user_id, external_id, credential_id }` |
 * | `login.failed` | `{ app_id, code }` |
 * | `credential.revoked` | `{ app_id, end_user_id, credential_id }` |
 * | `credential.renamed` | `{ app_id, end_user_id, credential_id }` |
 * | `user.revoked` | `{ app_id, end_user_id, revoked }` |
 * | `user.deleted` | `{ app_id, end_user_id, credentials_deleted }` |
 * | `recovery.issued` | `{ app_id, external_id, reason }` |
 *
 * A redelivery has the same `webhook-id` header.
 */
final class WebhookEvent
{
    /** The event type, such as `login.succeeded`. */
    public readonly string $type;
    /** ISO 8601. */
    public readonly ?string $timestamp;
    /** @var array<string, mixed> The event's data. */
    public readonly array $data;
    /** @var array<string, mixed> The whole verified body. */
    public readonly array $raw;

    /** @param array<string, mixed> $body A verified body with `type` and `data`. */
    public function __construct(array $body)
    {
        $this->type = (string) $body['type'];
        $this->timestamp = isset($body['timestamp']) && is_string($body['timestamp']) ? $body['timestamp'] : null;
        $this->data = $body['data'];
        $this->raw = $body;
    }
}
