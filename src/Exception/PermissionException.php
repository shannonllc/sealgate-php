<?php

declare(strict_types=1);

namespace SealGate\Exception;

/** 403: `credential_revoked`, `origin_not_allowed` or `recovery_not_allowed`. See `errorCode`. */
class PermissionException extends SealGateException
{
}
