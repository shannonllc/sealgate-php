<?php

declare(strict_types=1);

namespace SealGate\Http;

use SealGate\Exception\SealGateException;

/**
 * The result of one attempt.
 *
 * @internal
 */
final class Outcome
{
    private function __construct(
        public readonly mixed $value,
        public readonly ?SealGateException $error,
        public readonly bool $retryable,
        public readonly ?float $wait,
    ) {
    }

    public static function ok(mixed $value): self
    {
        return new self($value, null, false, null);
    }

    public static function fail(SealGateException $error, bool $retryable, ?float $wait = null): self
    {
        return new self(null, $error, $retryable, $wait);
    }
}
