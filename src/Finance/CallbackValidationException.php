<?php

namespace HScript\Finance;

use InvalidArgumentException;

/** A fixed, redacted callback-contract reason safe for the audit sink. */
final class CallbackValidationException extends InvalidArgumentException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
