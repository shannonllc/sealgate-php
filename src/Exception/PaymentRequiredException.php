<?php

declare(strict_types=1);

namespace SealGate\Exception;

/** 402 `mau_limit`: an organization without a payment method reached the monthly MAU limit. */
class PaymentRequiredException extends SealGateException
{
}
