<?php

declare(strict_types=1);

namespace HScript\Finance;

/** Simple form/AJAX preview: no compound, calendar, return or fee simulation. */
final class DepositSummary
{
    public static function project(mixed $sum, array $plan): array
    {
        if (is_string($sum)) $sum=str_replace([' ', ','],['', '.'],$sum);
        $principal=ReadAmount::decimal($sum);
        if ($principal->isNegative()) $principal=Decimal::of(0);
        $period=$principal->multipliedBy(ReadAmount::decimal($plan['pPerc'] ?? null))->multipliedBy(Decimal::of('0.01'));
        $count=!empty($plan['pNPer']) ? (int)$plan['pNPer'] : 1;
        $profit=$period->multipliedBy(Decimal::of($count));
        return ['summary_sum'=>(string)$principal,'period_profit'=>(string)$period,
            'total_profit'=>(string)$profit,'summary_return'=>(string)$principal->plus($profit)];
    }
}
