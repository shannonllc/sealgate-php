<?php

declare(strict_types=1);

namespace SealGate\Resources;

/** Passkey login (`/v1/login/*`): start with `options()`, finish with `verify()`, then start your session. */
final class Login extends Resource
{
    /**
     * Starts a login. Without `external_id` the user picks a passkey saved on the device (also for autofill).
     * Not retried on a 5xx or a connection error.
     *
     * @param array{external_id?: string} $params
     * @param array{timeout?: float|int, max_retries?: int} $options
     * @return array{challenge_id: string, options: array<string, mixed>}
     */
    public function options(array $params = [], array $options = []): array
    {
        $body = self::object(self::pick($params, ['external_id']));
        return $this->http->request('POST', '/v1/login/options', false, body: $body, hasBody: true, options: self::opts($options));
    }

    /**
     * Verifies the signature (`AuthenticationResponseJSON` from the browser, decoded as an array) and returns the
     * user. Not retried on a 5xx or a connection error.
     *
     * @param array{challenge_id: string, response: array<string, mixed>} $params
     * @param array{timeout?: float|int, max_retries?: int} $options
     * @return array{end_user_id: string, external_id: string, credential_id: string, user_verified: bool, password_allowed: bool}
     */
    public function verify(array $params, array $options = []): array
    {
        $body = self::pick($params, ['challenge_id', 'response']);
        return $this->http->request('POST', '/v1/login/verify', false, body: $body, hasBody: true, options: self::opts($options));
    }
}
