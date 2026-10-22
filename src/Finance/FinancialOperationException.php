<?php

namespace HScript\Finance;

use RuntimeException;

/** An error code safe for the owning API or form; throwing aborts its work. */
final class FinancialOperationException extends RuntimeException
{
    public static function check(mixed $result): mixed
    {
        if (is_string($result)) throw new self($result);
        return $result;
    }
}
