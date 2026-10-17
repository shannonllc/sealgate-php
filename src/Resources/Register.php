<?php

declare(strict_types=1);

namespace SealGate\Resources;

/** Passkey registration (`/v1/register/*`): start with `options()`, finish with `verify()`. */
final class Register extends Resource
{
    /**
     * Starts a registration. Return the result to the browser SDK as is. A user who already has a passkey needs
     * `signed_in` => true (decide it from your session, never from a value the browser sent) or a `recovery_token`.
     * Not retried on a 5xx or a connection error.
     *
     * @param array{external_id: string, display_name: string, signed_in?: bool, recovery_token?: string} $params
     * @param array{timeout?: float|int, max_retries?: int} $options
     * @return array{challenge_id: string, end_user_id: string, options: array<string, mixed>}
     */
    public function options(array $params, array $options = []): array
    {
        $body = self::pick($params, ['external_id', 'display_name', 'signed_in', 'recovery_token']);
        return $this->http->request('POST', '/v1/register/options', false, body: $body, hasBody: true, options: self::opts($options));
    }

    /**
     * Verifies the authenticator's response (`RegistrationResponseJSON` from the browser, decoded as an array) and
     * saves the passkey. The challenge is single use, so this is not retried on a 5xx or a connection error.
     *
     * @param array{challenge_id: string, response: array<string, mixed>, name?: string} $params
     * @param array{timeout?: float|int, max_retries?: int} $options
     * @return array{credential_id: string, end_user_id: string}
     */
    public function verify(array $params, array $options = []): array
    {
        $body = self::pick($params, ['challenge_id', 'response', 'name']);
        return $this->http->request('POST', '/v1/register/verify', false, body: $body, hasBody: true, options: self::opts($options));
    }
}
