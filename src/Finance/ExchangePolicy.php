<?php

declare(strict_types=1);

namespace HScript\Finance;

use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Keep the historical source rounding before the target conversion. */
final class ExchangePolicy
{
    public static function convert(Decimal $amount, Rate $rate, Percentage $adjustment, bool $out, int $scale): Decimal
    {
        if ($amount->compareTo(Decimal::of(0)) <= 0) throw new InvalidArgumentException('exchange_amount_invalid');
        $fraction = $adjustment->decimal()->dividedBy(Decimal::of(100), 20, RoundingMode::Unnecessary);
        $factor = $out ? Decimal::of(1)->plus($fraction) : Decimal::of(1)->minus($fraction);
        if ($factor->compareTo(Decimal::of(0)) <= 0) throw new InvalidArgumentException('exchange_factor_invalid');
        $adjustedRate = $rate->decimal()->multipliedBy($factor);
        return $out
            ? $amount->multipliedBy($adjustedRate)->rounded($scale, RoundingMode::HalfUp)
            : $amount->dividedBy($adjustedRate, $scale, RoundingMode::HalfUp);
    }
}
