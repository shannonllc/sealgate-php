<?php

declare(strict_types=1);

namespace SealGate\Exception;

/** 401 `verification_failed`: the passkey response did not verify. */
class VerificationException extends SealGateException
{
}
