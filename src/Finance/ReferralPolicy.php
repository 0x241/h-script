<?php

declare(strict_types=1);

namespace HScript\Finance;

use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Referral percentages and tier metrics have precision independent of money. */
final class ReferralPolicy
{
    private const NUMBER = '(?:\\d+(?:[.,]\\d*)?|[.,]\\d+)(?:[eE][+-]?\\d+)?';

    public static function percentage(mixed $value): Decimal
    {
        if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException('ref_config_wrong');
        $text = str_replace(',', '.', trim(str_replace(' ', '', (string)$value)));
        if (strlen($text) > 128 || !preg_match('/^([+-]?)(\\d+(?:\\.\\d*)?|\\.\\d+)([eE][+-]?\\d{1,2})?$/D', $text, $parts)) {
            throw new InvalidArgumentException('ref_config_wrong');
        }
        // PHP numeric configuration historically accepts .5 and 5., also before an exponent.
        $mantissa = $parts[2];
        if (str_starts_with($mantissa, '.')) $mantissa = '0' . $mantissa;
        if (str_ends_with($mantissa, '.')) $mantissa = substr($mantissa, 0, -1);
        if (isset($parts[3])) {
            // Percentages keep Decimal's 36/18 bound, independent of request-money precision.
            $shift = (int)substr($parts[3], 1);
            if (abs($shift) > 54) throw new InvalidArgumentException('ref_config_wrong');
            [$whole, $fraction] = array_pad(explode('.', $mantissa, 2), 2, '');
            $digits = $whole . $fraction;
            $point = strlen($whole) + $shift;
            if ($point < 0) { $digits = str_repeat('0', -$point) . $digits; $point = 0; }
            if ($point > strlen($digits)) $digits .= str_repeat('0', $point - strlen($digits));
            $whole = ltrim(substr($digits, 0, $point), '0') ?: '0';
            $fraction = rtrim(substr($digits, $point), '0');
            $mantissa = $whole . ($fraction === '' ? '' : '.' . $fraction);
        }
        try { return Decimal::of(($parts[1] === '-' ? '-' : '') . $mantissa); }
        catch (InvalidArgumentException $error) { throw new InvalidArgumentException('ref_config_wrong', 0, $error); }
    }

    public static function payout(Decimal $source, Decimal $percent, int $scale): Decimal
    {
        if ($source->isNegative()) throw new InvalidArgumentException('sum_wrong');
        if ($percent->isNegative()) throw new InvalidArgumentException('ref_config_wrong');
        // Preserve the existing calcPerc(..., 6), then receiving-currency rounding.
        $amount = Percentage::of((string)$percent)->apply($source, 6, RoundingMode::HalfUp)
            ->rounded($scale, RoundingMode::HalfUp);
        StoredAmount::exact($amount);
        return $amount;
    }

    /** Keep configured order and stop at the first threshold above the metric. */
    public static function tier(Decimal $metric, array $entries): array
    {
        if ($metric->isNegative()) throw new InvalidArgumentException('ref_config_wrong');
        $percent = Decimal::of(0);
        $level = 0;
        $parsed = [];
        foreach (array_values($entries) as $entry) {
            if (!is_string($entry)) throw new InvalidArgumentException('ref_config_wrong');
            // The separator is distinct from a signed exponent or percentage.
            $number = self::NUMBER;
            if (!preg_match('/^([+]?' . $number . ')-([+-]?' . $number . ')$/D',
                trim(str_replace(' ', '', $entry)), $parts)) throw new InvalidArgumentException('ref_config_wrong');
            $minimum = self::percentage($parts[1]);
            $candidate = self::percentage($parts[2]);
            if ($minimum->isNegative()) throw new InvalidArgumentException('ref_config_wrong');
            $parsed[] = [$minimum, $candidate];
        }
        // Validate every entry first, including those beyond the selected tier.
        foreach ($parsed as $i => [$minimum, $candidate]) {
            // The legacy memo labels the last inspected entry, including a break.
            $level = $i + 1;
            if ($minimum->compareTo($metric) > 0) break;
            $percent = $candidate;
        }
        return ['percent' => $percent, 'level' => $level];
    }
}
