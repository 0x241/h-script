<?php

declare(strict_types=1);

namespace HScript\Finance;

use InvalidArgumentException;

final class CurrencyScale
{
    /** Legacy zero/negative currency scale inherits the configured UI scale. */
    public static function resolve(array $currency, array $configuration): int
    {
        $scale = self::integer($currency['cNumDec'] ?? 0);
        if ($scale <= 0) $scale = self::integer($configuration['UI_NumDec'] ?? 2);
        if ($scale < 0 || $scale > 8) throw new InvalidArgumentException('money_scale_invalid');
        return $scale;
    }

    private static function integer(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^-?\d{1,2}$/D', (string)$value))
            throw new InvalidArgumentException('money_scale_invalid');
        return (int)$value;
    }
}
