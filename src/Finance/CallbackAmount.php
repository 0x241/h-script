<?php

namespace HScript\Finance;

/** Decimal comparison at the currency scale, without binary floats or overflow. */
final class CallbackAmount
{
    public static function atScale(mixed $value, int $scale): string
    {
        if ($scale < 0 || $scale > 8 || (!is_string($value) && !is_int($value))
            || !preg_match('/^\d{1,12}(?:\.\d{1,8})?$/D', (string)$value))
            throw new CallbackValidationException('callback_amount_invalid');
        [$whole, $fraction] = array_pad(explode('.', (string)$value, 2), 2, '');
        // Extra zeroes are harmless; sub-unit differences are never rounded away.
        if (trim(substr($fraction, $scale), '0') !== '')
            throw new CallbackValidationException('callback_amount_precision');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = str_pad(substr($fraction, 0, $scale), $scale, '0');
        if (trim($whole . $fraction, '0') === '')
            throw new CallbackValidationException('callback_amount_invalid');
        return $whole . ($scale ? '.' . $fraction : '');
    }
}
