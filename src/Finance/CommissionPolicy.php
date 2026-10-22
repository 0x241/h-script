<?php

declare(strict_types=1);

namespace HScript\Finance;

use Brick\Math\RoundingMode;

/** Preserve the agreed two-stage rounding and min-before-max commission rule. */
final class CommissionPolicy
{
    public static function calculate(
        Decimal $amount, Percentage $percentage, Decimal $minimum, Decimal $maximum,
        string $currency, int $scale,
    ): Money {
        $fee = $percentage->apply($amount, 6, RoundingMode::HalfUp)->rounded($scale, RoundingMode::HalfUp);
        if ($fee->compareTo($minimum) < 0) $fee = $minimum;
        elseif ($maximum->compareTo(Decimal::of(0)) > 0 && $fee->compareTo($maximum) > 0) $fee = $maximum;
        return Money::fromDecimal($fee, $currency, $scale, RoundingMode::HalfUp);
    }
}
