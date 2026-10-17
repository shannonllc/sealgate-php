<?php

declare(strict_types=1);

namespace SealGate\Exception;

/**
 * Base class for every exception thrown by the SDK.
 *
 * The API error code is `errorCode` (`Exception::getCode()` is an integer and is not used). Branch on it.
 */
class SealGateException extends \RuntimeException
{
    /**
     * @param int|null $status HTTP status. Null when no response was received.
     * @param string|null $errorCode API error code, such as `invalid_request`, `credential_revoked` or `challenge_expired`.
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}
