<?php

declare(strict_types=1);

namespace HScript\Finance;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\NumberSyntax;
use InvalidArgumentException;

/** Read-only legacy numeric values; never use this fallback at a financial write boundary. */
final class ReadAmount
{
    public static function decimal(mixed $value): Decimal
    {
        if ($value instanceof Decimal) return $value;
        try {
            // Explicit adapter for legacy JSON/configuration ingress, not float arithmetic.
            if (is_float($value)) {
                if (!is_finite($value)) throw new InvalidArgumentException('decimal_invalid');
                $value = json_encode($value, JSON_THROW_ON_ERROR);
            }
            if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException('decimal_invalid');
            $text = trim((string)$value);
            if (strlen($text) > 128) throw new InvalidArgumentException('decimal_invalid');
            return Decimal::of((string)BigDecimal::parse($text, NumberSyntax::SCIENTIFIC, 55));
        } catch (InvalidArgumentException|MathException) {
            // Missing/NULL/non-numeric read values historically meant zero. No DB rewrite.
            return Decimal::of(0);
        }
    }

    /** SQL aggregates retain six places and may exceed the range of an individual row. */
    public static function stored(mixed $value): string
    {
        return DecimalOutput::stored(self::decimal($value));
    }
}
