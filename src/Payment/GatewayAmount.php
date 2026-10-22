<?php

declare(strict_types=1);

namespace HScript\Payment;

use Brick\Math\RoundingMode;
use HScript\Finance\{Decimal,Money};
use InvalidArgumentException;
use Throwable;

/** Exact application envelope; a payout must never round its reserved net amount. */
final class GatewayAmount
{
    public static function exact(mixed $amount, string $currency, int $scale): string
    {
        return self::format($amount,$currency,$scale,RoundingMode::Unnecessary);
    }

    /** The WebMoney checkout historically used HALF_UP cents, separately from payouts. */
    public static function checkout(mixed $amount, string $currency, int $scale): string
    {
        return self::format($amount,$currency,$scale,RoundingMode::HalfUp);
    }

    private static function format(mixed $amount,string $currency,int $scale,RoundingMode $rounding): string
    {
        try {
            if ((!is_string($amount) && !is_int($amount))
                || !preg_match('/^\d{1,12}(?:\.\d{1,8})?$/D',(string)$amount)) throw new InvalidArgumentException();
            $value=Decimal::of($amount);
            $money=Money::fromDecimal($value,$currency,$scale,$rounding);
            if ($money->decimal()->compareTo(Decimal::of(0))<=0) throw new InvalidArgumentException();
            return (string)$money;
        } catch (Throwable) {
            throw new InvalidArgumentException('payment_amount_invalid');
        }
    }
}
