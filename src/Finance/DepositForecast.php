<?php

declare(strict_types=1);

namespace HScript\Finance;

use Brick\Math\BigDecimal;
use Brick\Math\NumberSyntax;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use OverflowException;

/** Read-only legacy calculator: every step is HALF_UP at two places. */
final class DepositForecast
{
    public const MAX_PERIODS = 10000;

    /** Bound expansion before parsing; accept the calculator's comma decimal input. */
    public static function input(mixed $value): Decimal
    {
        if (is_float($value)) {
            if (!is_finite($value)) throw new InvalidArgumentException('forecast_input_invalid');
            $value = json_encode($value, JSON_THROW_ON_ERROR);
        }
        if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException('forecast_input_invalid');
        $text = str_replace(',', '.', trim((string)$value));
        if (strlen($text) > 128) throw new InvalidArgumentException('forecast_input_invalid');
        return Decimal::of((string)BigDecimal::parse($text, NumberSyntax::SCIENTIFIC, 55));
    }

    /** This preview does not model deposit fees, currency conversion or calendar accruals. */
    public static function calculate(Decimal $sum, Decimal $bonus, Decimal $percent, Decimal $return,
        Decimal $compound, int $periods): array
    {
        if ($periods < 0 || $periods > self::MAX_PERIODS) throw new InvalidArgumentException('forecast_periods_invalid');
        if ($sum->compareTo(Decimal::of(0)) <= 0 || $compound->isNegative()
            || $compound->compareTo(Decimal::of(100)) > 0) throw new InvalidArgumentException('forecast_input_invalid');
        $base = self::bounded($sum->rounded(2, RoundingMode::HalfUp));
        $bonusSum = self::percentage($base, $bonus);
        $work = self::bounded($base->plus($bonusSum));
        $capitalized = $profit = Decimal::of('0.00');
        for ($i = 0; $i < $periods; $i++) {
            $periodProfit = self::percentage($work, $percent);
            $periodCapitalized = self::percentage($periodProfit, $compound);
            $capitalized = self::bounded($capitalized->plus($periodCapitalized));
            $profit = self::bounded($profit->plus($periodProfit)->minus($periodCapitalized));
            $work = self::bounded($work->plus($periodCapitalized));
        }
        $depositReturn = self::percentage($work->minus($capitalized), $return);
        $totalProfit = self::bounded($profit->plus($capitalized));
        return [
            'bonus_sum' => (string)$bonusSum,
            'profit_sum' => (string)$totalProfit,
            'deposit_return_sum' => (string)$depositReturn,
            'total_return_sum' => (string)self::bounded($depositReturn->plus($totalProfit)),
        ];
    }

    private static function percentage(Decimal $amount, Decimal $percent): Decimal
    {
        return self::bounded($amount->multipliedBy($percent)->dividedBy(Decimal::of(100), 2, RoundingMode::HalfUp));
    }

    private static function bounded(Decimal $value): Decimal
    {
        if (strlen(explode('.', ltrim((string)$value, '-'), 2)[0]) > 36)
            throw new OverflowException('forecast_overflow');
        return $value;
    }
}
