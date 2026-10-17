<?php

declare(strict_types=1);

namespace SealGate;

/** Types of the webhooks sent by SealGate. */
final class EventType
{
    public const REGISTER_COMPLETED = 'register.completed';
    public const REGISTER_FAILED = 'register.failed';
    public const LOGIN_SUCCEEDED = 'login.succeeded';
    public const LOGIN_FAILED = 'login.failed';
    public const CREDENTIAL_REVOKED = 'credential.revoked';
    public const CREDENTIAL_RENAMED = 'credential.renamed';
    public const USER_REVOKED = 'user.revoked';
    public const USER_DELETED = 'user.deleted';
    public const RECOVERY_ISSUED = 'recovery.issued';
}
