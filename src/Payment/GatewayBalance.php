<?php

declare(strict_types=1);

namespace HScript\Payment;

use Brick\Math\{BigDecimal,NumberSyntax};
use HScript\Finance\Decimal;
use InvalidArgumentException;
use Throwable;

/** Read-only provider balances; legacy JSON numbers are an explicit ingress adapter. */
final class GatewayBalance
{
    public static function read(mixed $value): string
    {
        try {
            if (is_float($value)) {
                if (!is_finite($value)) throw new InvalidArgumentException();
                $value=json_encode($value,JSON_THROW_ON_ERROR);
            }
            if ((!is_string($value) && !is_int($value)) || strlen((string)$value)>128) throw new InvalidArgumentException();
            $amount=Decimal::of((string)BigDecimal::parse((string)$value,NumberSyntax::SCIENTIFIC,55));
            if ($amount->isNegative()) throw new InvalidArgumentException();
            return (string)$amount;
        } catch (Throwable) { throw new InvalidArgumentException('provider_balance_invalid'); }
    }
}
