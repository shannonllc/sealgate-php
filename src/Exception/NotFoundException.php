<?php

declare(strict_types=1);

namespace SealGate\Exception;

/** 404 `not_found`: the user, passkey or app does not exist for this API key. */
class NotFoundException extends SealGateException
{
}
