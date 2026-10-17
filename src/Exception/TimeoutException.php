<?php

declare(strict_types=1);

namespace SealGate\Exception;

/** The request did not complete within `timeout`. */
class TimeoutException extends ConnectionException
{
}
