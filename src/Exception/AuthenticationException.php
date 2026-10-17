<?php

declare(strict_types=1);

namespace SealGate\Exception;

/** 401 `invalid_api_key`: the API key is missing, wrong or revoked. */
class AuthenticationException extends SealGateException
{
}
