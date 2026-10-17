<?php

declare(strict_types=1);

namespace SealGate;

/**
 * Verifies a webhook sent by SealGate (`login.succeeded`, `credential.revoked` and others) and returns the event.
 *
 * @param string $payload The raw request body, exactly as received (not a decoded array).
 * @param array<string, string|list<string>>|object $headers `$_SERVER`, an array of name => value or a PSR-7 request.
 * @param string|list<string> $secret The signing secret (`whsec_...`) shown under the environment settings' delivery
 *        log and signing key, or a list while you switch to a new one.
 * @param int $tolerance Allowed clock difference in seconds, both ways. Default 300.
 * @throws Exception\WebhookVerificationException when verification fails.
 */
function verifyWebhook(string $payload, array|object $headers, string|array $secret, int $tolerance = 300): WebhookEvent
{
    return (new Webhook($secret, $tolerance))->verifyEvent($payload, $headers);
}
