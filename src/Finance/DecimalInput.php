<?php

declare(strict_types=1);

namespace HScript\Finance;

use InvalidArgumentException;

/** Explicit ingress for not-yet-migrated callers; never used for arithmetic. */
final class DecimalInput
{
    public static function read(mixed $value): Decimal
    {
        if (is_float($value)) {
            if (!is_finite($value)) throw new InvalidArgumentException('decimal_invalid');
            $value = json_encode($value, JSON_THROW_ON_ERROR);
        }
        if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException('decimal_invalid');
        $text = str_replace(',', '.', trim((string)$value));
        $negative = str_starts_with($text, '-');
        if ($negative) $text = substr($text, 1);
        // RequestDecimal expands only bounded exponents and rejects >12/8 digits.
        // Plain stored text keeps its independent rate/percentage precision.
        if (str_contains(strtolower($text), 'e')) $text = RequestDecimal::normalize($text, true);
        elseif (str_starts_with($text, '+')) $text = substr($text, 1);
        return Decimal::of(($negative ? '-' : '') . $text);
    }
}
