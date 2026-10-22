<?php

declare(strict_types=1);

namespace HScript\Telemetry;

use Brick\Math\{BigDecimal,NumberSyntax,RoundingMode};
use HScript\Finance\{Decimal,DecimalOutput};
use InvalidArgumentException;
use Throwable;

/** Public amounts have explicit HALF_UP eight-place display/SQL aggregation precision. */
final class TelemetryAmount
{
    public static function read(mixed $value): string
    {
        try { return self::metric($value); }
        catch (InvalidArgumentException) { return '0.00000000'; }
    }

    /** A cache write refuses invalid input; it must not replace it with a zero metric. */
    public static function metric(mixed $value): string
    {
        $amount=self::parse($value);
        if ($amount->isNegative()) throw new InvalidArgumentException('telemetry_amount_invalid');
        return (string)$amount->toScale(8,RoundingMode::HalfUp);
    }

    /** Schema2 is canonical fixed8 text; the schema1 JSON-number/hash path stays separate. */
    public static function wire(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^(?:0|[1-9]\d{0,15})\.\d{8}$/D',$value)
            || BigDecimal::of($value)->isGreaterThan('1000000000000000.00000000'))
            throw new InvalidArgumentException('telemetry_amount_invalid');
        return $value;
    }

    /** Preserve the landing page's near-integer threshold before display rounding. */
    public static function landing(mixed $value): string
    {
        try { $amount=self::parse($value); }
        catch (InvalidArgumentException) { $amount=BigDecimal::zero(); }
        if ($amount->isNegative()) $amount=BigDecimal::zero();
        $integer=$amount->toScale(0,RoundingMode::HalfUp);
        $scale=$amount->minus($integer)->abs()->isLessThan('0.000001') ? 0 : 2;
        return DecimalOutput::grouped((string)$amount,$scale,',');
    }

    private static function parse(mixed $value): BigDecimal
    {
        try {
            if ($value instanceof Decimal) $value=(string)$value;
            // Old stored JSON numeric values remain readable; lost source digits are not recoverable.
            if (is_float($value)) {
                if (!is_finite($value)) throw new InvalidArgumentException();
                $value=json_encode($value,JSON_THROW_ON_ERROR);
            }
            if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException();
            $text=trim((string)$value);
            if (strlen($text)>128) throw new InvalidArgumentException();
            if (preg_match('/^-?\d{1,80}(?:\.\d{1,36})?$/D',$text)) return BigDecimal::of($text);
            return BigDecimal::of((string)Decimal::of((string)BigDecimal::parse($text,NumberSyntax::SCIENTIFIC,55)));
        } catch (Throwable) { throw new InvalidArgumentException('telemetry_amount_invalid'); }
    }
}
