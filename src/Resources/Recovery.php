<?php

declare(strict_types=1);

namespace SealGate\Resources;

/** Recovery for a user who lost their device (`/v1/recovery/start`). */
final class Recovery extends Resource
{
    /**
     * Issues a recovery token (15 minutes, single use) for `register->options(['recovery_token' => ...])`. SealGate
     * does not check who the user is: do that yourself first. Not retried on a 5xx or a connection error.
     *
     * @param array{external_id: string, reason?: string} $params
     * @param array{timeout?: float|int, max_retries?: int} $options
     * @return array{recovery_token: string, expires_at: string}
     */
    public function start(array $params, array $options = []): array
    {
        return $this->http->request('POST', '/v1/recovery/start', false, body: self::pick($params, ['external_id', 'reason']), hasBody: true, options: self::opts($options));
    }
}
