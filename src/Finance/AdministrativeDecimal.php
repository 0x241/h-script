<?php

declare(strict_types=1);

namespace HScript\Finance;

use Brick\Math\BigDecimal;
use Brick\Math\NumberSyntax;
use Brick\Math\RoundingMode;
use Brick\Math\Exception\RoundingNecessaryException;
use InvalidArgumentException;
use OverflowException;

/** Bounded form text and exact signed SQL storage, without a float write adapter. */
final class AdministrativeDecimal
{
    public static function read(mixed $value, bool $allowBlank = true): Decimal
    {
        if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException('admin_decimal_invalid');
        $text = str_replace([' ', ','], ['', '.'], trim((string)$value));
        if ($text === '' && $allowBlank) $text = '0';
        if (strlen($text) > 128) throw new InvalidArgumentException('admin_decimal_invalid');
        return Decimal::of((string)BigDecimal::parse($text, NumberSyntax::SCIENTIFIC, 55)->strippedOfTrailingZeros());
    }

    public static function stored(Decimal $value, int $precision, int $scale): string
    {
        if ($scale < 0 || $scale > 18 || $precision <= $scale || $precision > 36)
            throw new InvalidArgumentException('admin_decimal_storage_invalid');
        try { $stored = $value->rounded($scale, RoundingMode::Unnecessary); }
        catch (RoundingNecessaryException $e) { throw new InvalidArgumentException('admin_decimal_precision', 0, $e); }
        $maximum = Decimal::of(str_repeat('9', $precision - $scale) . ($scale ? '.' . str_repeat('9', $scale) : ''));
        if ($stored->compareTo($maximum) > 0 || $stored->compareTo($maximum->negated()) < 0)
            throw new OverflowException('admin_decimal_overflow');
        return (string)$stored;
    }
}
