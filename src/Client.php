<?php

declare(strict_types=1);

namespace SealGate;

use SealGate\Exception\SealGateException;
use SealGate\Http\CurlTransport;
use SealGate\Http\HttpClient;
use SealGate\Http\Transport;
use SealGate\Resources\Apps;
use SealGate\Resources\Credentials;
use SealGate\Resources\Login;
use SealGate\Resources\Recovery;
use SealGate\Resources\Register;
use SealGate\Resources\Users;

/** Client for the SealGate API (`/v1`). Use it on your server only: never ship the API key to a browser or an app. */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://api.sealgate.jp';
    /** Seconds per attempt. */
    public const DEFAULT_TIMEOUT = 30.0;
    public const DEFAULT_MAX_RETRIES = 2;
    public const API_KEY_ENV = 'SEALGATE_API_KEY';

    public readonly Register $register;
    public readonly Login $login;
    public readonly Users $users;
    public readonly Credentials $credentials;
    public readonly Recovery $recovery;
    public readonly Apps $apps;
    public readonly string $baseUrl;
    /** @internal */
    public readonly HttpClient $http;

    /**
     * @param string|null $apiKey `sk_live_...` or `sk_test_...`. Defaults to the `SEALGATE_API_KEY` environment variable.
     * @param array{base_url?: string, timeout?: float|int, max_retries?: int, transport?: Transport} $options
     *        `timeout` is seconds per attempt (default 30), `max_retries` the retries after the first attempt (default 2),
     *        `transport` a custom transport (default: cURL).
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        $key = $apiKey ?? trim((string) getenv(self::API_KEY_ENV));
        if ($key === '') {
            throw new SealGateException("Missing API key. Pass it to new Client('sk_...') or set " . self::API_KEY_ENV . '.');
        }
        $this->baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $this->http = new HttpClient(
            $key,
            $this->baseUrl,
            (float) ($options['timeout'] ?? self::DEFAULT_TIMEOUT),
            (int) ($options['max_retries'] ?? self::DEFAULT_MAX_RETRIES),
            $options['transport'] ?? new CurlTransport(),
        );
        $this->register = new Register($this->http);
        $this->login = new Login($this->http);
        $this->users = new Users($this->http);
        $this->credentials = new Credentials($this->http);
        $this->recovery = new Recovery($this->http);
        $this->apps = new Apps($this->http);
    }
}
