<?php

declare(strict_types=1);

namespace HScript\Finance;

use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use OverflowException;
use InvalidArgumentException;

/** Exact boundary for current DECIMAL(13,6), without rounding existing money. */
final class StoredAmount
{
    public static function exact(Decimal $amount): string
    {
        try { $stored = $amount->rounded(6, RoundingMode::Unnecessary); }
        catch (RoundingNecessaryException $e) { throw new InvalidArgumentException('amount_storage_precision', 0, $e); }
        if ($stored->compareTo(Decimal::of('9999999.999999')) > 0
            || $stored->compareTo(Decimal::of('-9999999.999999')) < 0)
            throw new OverflowException('amount_storage_overflow');
        return (string)$stored;
    }
}
