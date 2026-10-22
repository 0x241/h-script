<?php

namespace HScript\Finance;

use InvalidArgumentException;

/** Canonical request text only; financial decimal arithmetic is a later phase. */
final class RequestDecimal
{
    public static function normalize(mixed $value, bool $allowZero = false): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) throw new InvalidArgumentException('invalid_decimal');
        if (is_float($value) && !is_finite($value)) throw new InvalidArgumentException('invalid_decimal');
        $text = is_float($value) ? json_encode($value, JSON_THROW_ON_ERROR) : trim((string)$value);
        $text = str_replace(',', '.', $text);
        if (strlen($text) > 64 || !preg_match('/^\+?(\d+)(?:\.(\d*))?(?:[eE]([+-]?\d{1,2}))?$/D', $text, $parts)) throw new InvalidArgumentException('invalid_decimal');
        $whole = $parts[1];
        $fraction = $parts[2] ?? '';
        $shift = (int)($parts[3] ?? 0);
        if (abs($shift) > 20) throw new InvalidArgumentException('invalid_decimal');
        $digits = $whole . $fraction;
        $point = strlen($whole) + $shift;
        if ($point < 0) { $digits = str_repeat('0', -$point) . $digits; $point = 0; }
        if ($point > strlen($digits)) $digits .= str_repeat('0', $point - strlen($digits));
        $whole = ltrim(substr($digits, 0, $point), '0') ?: '0';
        $fraction = rtrim(substr($digits, $point), '0');
        if (strlen($whole) > 12 || strlen($fraction) > 8 || (!$allowZero && $whole === '0' && $fraction === '')) throw new InvalidArgumentException('invalid_decimal');
        return $whole . ($fraction === '' ? '' : '.' . $fraction);
    }
}
