<?php

declare(strict_types=1);

namespace HScript\Finance;

use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** New earnings round at six places, then at the configured currency scale. */
final class DepositPolicy
{
    public static function percentage(Decimal $amount, Decimal $percent, int $scale): Decimal
    {
        if ($amount->isNegative() || $percent->isNegative()) throw new InvalidArgumentException('deposit_negative');
        return $amount->multipliedBy($percent)->dividedBy(Decimal::of(100), 6, RoundingMode::HalfUp)
            ->rounded($scale, RoundingMode::HalfUp);
    }

    public static function compound(Decimal $percent): Decimal
    {
        $percent = $percent->rounded(2, RoundingMode::HalfUp);
        if ($percent->isNegative() || $percent->compareTo(Decimal::of(100)) > 0)
            throw new InvalidArgumentException('compnd_wrong');
        return $percent;
    }

    public static function stored(Decimal $value): string
    {
        if ($value->isNegative()) throw new InvalidArgumentException('deposit_negative');
        return StoredAmount::exact($value);
    }

    public static function compoundCap(Decimal $principal, Decimal $compounded, Decimal $minimumNative): Decimal
    {
        $cap = $principal->minus($minimumNative);
        if ($cap->isNegative()) $cap = Decimal::of(0);
        return $compounded->compareTo($cap) > 0 ? $cap : $compounded;
    }

    /** Existing principal and compounded earnings keep every stored decimal. */
    public static function accrual(Decimal $principal, Decimal $compounded, Decimal $previousProfit,
        Decimal $percent, Decimal $returnPercent, Decimal $compoundPercent, bool $terminal, int $scale): array
    {
        foreach ([$principal, $compounded, $previousProfit] as $value) self::stored($value);
        if ($compounded->compareTo($principal) > 0 || $returnPercent->isNegative())
            throw new InvalidArgumentException('deposit_negative');
        $compoundPercent = self::compound($compoundPercent);
        $profit = self::percentage($principal, $percent, $scale);
        $worked = $principal->minus($compounded);
        $returned = $terminal ? $compounded->plus($returnPercent->compareTo(Decimal::of(100)) === 0
            ? $worked : self::percentage($worked, $returnPercent, $scale)) : Decimal::of(0);
        $reinvest = $terminal ? Decimal::of(0) : self::percentage($profit, $compoundPercent, $scale);
        $totalProfit = $previousProfit->plus($profit);
        foreach ([$profit, $returned, $reinvest, $totalProfit, $profit->plus($returned)] as $value) self::stored($value);
        return ['profit'=>$profit, 'returned'=>$returned, 'reinvest'=>$reinvest, 'totalProfit'=>$totalProfit,
            'release'=>$terminal ? $principal : Decimal::of(0), 'worked'=>$worked];
    }
}
