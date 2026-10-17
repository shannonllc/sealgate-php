<?php

declare(strict_types=1);

namespace SealGate\Tests;

use SealGate\Webhook;

/** A Webhook whose clock is fixed. */
final class FixedClockWebhook extends Webhook
{
    /** @param string|list<string> $secret */
    public function __construct(string|array $secret, private readonly int $at, int $tolerance = 300)
    {
        parent::__construct($secret, $tolerance);
    }

    protected function now(): int
    {
        return $this->at;
    }
}
