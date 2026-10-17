<?php

declare(strict_types=1);

namespace SealGate\Exception;

/** 410 `challenge_expired`: the challenge expired (5 minutes), was already used, or is unknown. */
class ChallengeExpiredException extends SealGateException
{
}
