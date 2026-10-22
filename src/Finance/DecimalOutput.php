<?php

declare(strict_types=1);

namespace HScript\Finance;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\NumberSyntax;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Stored precision and display precision are separate serialization boundaries. */
final class DecimalOutput
{
    /** Fixed-format grouping without passing exact decimal text through number_format(). */
    public static function grouped(mixed $value, int $scale = 2, string $separator = ' '): string
    {
        $parts = explode('.', self::display($value, $scale), 2);
        $parts[0] = preg_replace_callback('/\B(?=(\d{3})+(?!\d))/', static fn(): string => $separator, $parts[0]);
        return implode('.', $parts);
    }

    /** Preserve six stored places, also for totals wider than a single stored row. */
    public static function stored(Decimal|string|int $value): string
    {
        return (string)($value instanceof Decimal ? $value : Decimal::of($value))
            ->rounded(6, RoundingMode::Unnecessary);
    }

    /** Legacy template ingress is explicit; no arithmetic is performed in floats. */
    public static function display(mixed $value, int $scale): string
    {
        if ($scale < 0 || $scale > 8) throw new InvalidArgumentException('money_scale_invalid');
        try {
            if ($value instanceof Money) $value = $value->decimal();
            if (!$value instanceof Decimal) {
                if (is_float($value)) {
                    if (!is_finite($value)) throw new InvalidArgumentException('decimal_invalid');
                    $value = json_encode($value, JSON_THROW_ON_ERROR);
                }
                if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException('decimal_invalid');
                $text = trim((string)$value);
                if (strlen($text) > 128) throw new InvalidArgumentException('decimal_invalid');
                // Library parsing bounds scientific expansion before allocating it.
                if (preg_match('/^-?\d{1,80}(?:\.\d{1,36})?$/D', $text)) {
                    // Fixed-format computed totals can have more places than a stored/input value:
                    // a six-place SQL sum times an eighteen-place rate needs twenty-four places.
                    $value = BigDecimal::of($text);
                } else {
                    $value = Decimal::of((string)BigDecimal::parse($text, NumberSyntax::SCIENTIFIC, 55));
                }
            }
        } catch (InvalidArgumentException|MathException) {
            // Retain the existing empty/malformed template amount fallback.
            $value = Decimal::of(0);
        }
        return (string)($value instanceof BigDecimal
            ? $value->toScale($scale, RoundingMode::HalfUp)
            : $value->rounded($scale, RoundingMode::HalfUp));
    }
}
