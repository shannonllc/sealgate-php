# sealgate-php

PHP SDK for [SealGate](https://sealgate.jp/), the passkey authentication API that adds passkey login to your existing login.

- PHP 8.1+ with ext-curl and ext-json. No other dependencies.
- Server-side only. In the browser, use [sealgate-browser](https://www.npmjs.com/package/sealgate-browser): it runs the passkey dialog and posts to your server, and your server relays to SealGate with this SDK.

## Install

```sh
composer require shannonllc/sealgate
```

## Quick start

```php
use SealGate\Client;

$sg = new Client('sk_live_...'); // or set SEALGATE_API_KEY

// Login, step 1: return the result to the browser SDK as is
$start = $sg->login->options(); // or ['external_id' => 'user-123']

// Login, step 2: verify what the browser SDK sent back, then start your own session
$body = json_decode(file_get_contents('php://input'), true);
$r = $sg->login->verify(['challenge_id' => $body['challenge_id'], 'response' => $body['response']]);
$_SESSION['user_id'] = $r['external_id'];
```

Results are associative arrays with the API's field names. API keys belong to an environment: Live keys start with `sk_live_` and Test keys with `sk_test_` (Test environment users are not billed). Use the API key on your server only.

## Registration

```php
// On a page where the user is logged in. Adding a second passkey needs signed_in:
// decide it from your session, never from a value the browser sent.
$start = $sg->register->options(['external_id' => $user->id, 'display_name' => $user->email, 'signed_in' => true]);

$done = $sg->register->verify(['challenge_id' => $body['challenge_id'], 'response' => $body['response'], 'name' => 'iPhone']);
```

## Users, passkeys and recovery

```php
$list = $sg->users->listCredentials('user-123'); // revoked ones have revoked_at
$sg->credentials->revoke('crd_...');
$sg->users->revoke('user-123'); // every passkey of the user
$sg->users->delete('user-123'); // cannot be undone

// A user who lost their device: check who they are yourself, then
$tok = $sg->recovery->start(['external_id' => 'user-123', 'reason' => 'checked at the counter']);
$start = $sg->register->options(['external_id' => 'user-123', 'display_name' => 'taro', 'recovery_token' => $tok['recovery_token']]);
```

## Webhooks

When you set a webhook URL in the dashboard's environment settings, SealGate POSTs events signed in the [Standard Webhooks](https://www.standardwebhooks.com/) format. The signing secret (`whsec_...`) is under the environment settings' delivery log and signing key. Pass the raw request body.

```php
use SealGate\EventType;
use SealGate\Exception\WebhookVerificationException;

use function SealGate\verifyWebhook;

try {
    $event = verifyWebhook(file_get_contents('php://input'), $_SERVER, getenv('SEALGATE_WEBHOOK_SECRET'));
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    exit;
}
if ($event->type === EventType::LOGIN_SUCCEEDED) {
    error_log($event->data['external_id']);
}
http_response_code(204);
```

Headers can be `$_SERVER`, an array of name => value, or a PSR-7 request. Verification fails when the signature does not match or the timestamp is more than 5 minutes off (the fourth argument, in seconds). Pass a list of secrets while you switch to a new one. A redelivery has the same `webhook-id` header. Events: `register.completed`, `register.failed`, `login.succeeded`, `login.failed`, `credential.revoked`, `credential.renamed`, `user.revoked`, `user.deleted` and `recovery.issued` (see `WebhookEvent`).

## API

| Method | Endpoint |
|---|---|
| `register->options([...])` | `POST /v1/register/options` |
| `register->verify([...])` | `POST /v1/register/verify` |
| `login->options([...])` | `POST /v1/login/options` |
| `login->verify([...])` | `POST /v1/login/verify` |
| `users->listCredentials($externalId)` | `GET /v1/users/:external_id/credentials` |
| `users->renameCredential($externalId, $credentialId, ['name' => ...])` | `PATCH /v1/users/:external_id/credentials/:id` |
| `users->revoke($externalId)` | `POST /v1/users/:external_id/revoke` |
| `users->delete($externalId)` | `DELETE /v1/users/:external_id` |
| `credentials->rename($credentialId, ['name' => ...])` | `PATCH /v1/credentials/:id` |
| `credentials->revoke($credentialId)` | `DELETE /v1/credentials/:id` |
| `recovery->start([...])` | `POST /v1/recovery/start` |
| `apps->get($appId)` | `GET /v1/apps/:id` |
| `apps->update($appId, [...])` | `PATCH /v1/apps/:id` |
| `apps->audit($appId, ['event' => ..., 'since' => ..., 'limit' => ...])` | `GET /v1/apps/:id/audit` |
| `apps->stats($appId)` | `GET /v1/apps/:id/stats` |

Every method takes `['timeout' => seconds, 'max_retries' => n]` as the last argument. The app ID (`app_...`) must be the API key's own app. The audit log is one list, newest first (up to 1000 entries); there are no further pages, so narrow it with `since` and `limit`.

## Errors

Every exception extends `SealGate\Exception\SealGateException` and has `status`, `errorCode` (the API error code) and the message. Branch on `errorCode`.

| Class | When |
|---|---|
| `ValidationException` | 400 `invalid_request` |
| `AuthenticationException` | 401 `invalid_api_key` |
| `VerificationException` | 401 `verification_failed`: the passkey response did not verify |
| `PaymentRequiredException` | 402 `mau_limit` |
| `PermissionException` | 403 `credential_revoked`, `origin_not_allowed`, `recovery_not_allowed` |
| `NotFoundException` | 404 `not_found` |
| `ChallengeExpiredException` | 410 `challenge_expired`: start again from options |
| `RateLimitException` | 429 `rate_limited`, after retries. `retryAfter` in seconds |
| `ApiException` | other responses, including 5xx |
| `ConnectionException` / `TimeoutException` | no response, or the timeout reached |
| `WebhookVerificationException` | `verifyWebhook` failed |

## Retries and timeouts

`new Client($key, ['timeout' => 30, 'max_retries' => 2])` are the defaults. 429 is retried for every request, waiting `retry-after` (up to one minute). 5xx, timeouts and connection errors are retried only for requests safe to repeat (GET, PATCH, DELETE), with exponential backoff from 0.5 s. POST requests (starting and finishing registration and login, revoking a user, issuing a recovery token) are not retried on those: a challenge can be used only once. Pass `'transport'` (a `SealGate\Http\Transport`) to send requests through another HTTP client.

## Links

- API reference: https://sealgate.jp/docs
- Changelog: [CHANGELOG.md](./CHANGELOG.md)
- License: MIT
