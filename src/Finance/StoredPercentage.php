<?php

declare(strict_types=1);

namespace HScript\Finance;

use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use OverflowException;

/** Exact signed DECIMAL(7,2) boundary; never delegate rounding to SQL. */
final class StoredPercentage
{
    public static function exact(Decimal $percentage): string
    {
        try { $stored = $percentage->rounded(2, RoundingMode::Unnecessary); }
        catch (RoundingNecessaryException $e) { throw new InvalidArgumentException('percentage_storage_precision', 0, $e); }
        if ($stored->compareTo(Decimal::of('99999.99')) > 0
            || $stored->compareTo(Decimal::of('-99999.99')) < 0)
            throw new OverflowException('percentage_storage_overflow');
        return (string)$stored;
    }
}
