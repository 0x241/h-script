<?php

declare(strict_types=1);

namespace HScript\Finance;

use Brick\Math\RoundingMode;
use HScript\Util\StringHelper;

/** Exact read projection of the enabled currencies supplied by authentication. */
final class WalletPortfolio
{
    public static function project(array $currencies): array
    {
        $groups = [];
        foreach ($currencies as $cid => &$currency) {
            $code = self::code($currency, $cid);
            $balance = ReadAmount::decimal($currency['wBal'] ?? null);
            if (!isset($groups[$code])) {
                $groups[$code] = ['cCurr'=>$code, 'cID'=>$cid, 'balance'=>Decimal::of(0), 'systems'=>0];
            }
            $groups[$code]['balance'] = $groups[$code]['balance']->plus($balance);
            $groups[$code]['systems']++;
            $currency['wBal'] = DecimalOutput::stored($balance);
        }
        unset($currency);
        foreach ($currencies as $cid => &$currency) {
            $total = $groups[self::code($currency, $cid)]['balance'];
            $currency['alloc_pct'] = (string)($total->compareTo(Decimal::of(0)) > 0
                ? Decimal::of($currency['wBal'])->multipliedBy(Decimal::of(100))->dividedBy($total, 1, RoundingMode::HalfUp)
                : Decimal::of(0)->rounded(1, RoundingMode::HalfUp));
        }
        unset($currency);
        usort($groups, static function (array $a, array $b): int {
            $comparison = $b['balance']->compareTo($a['balance']);
            return $comparison ?: strcmp((string)$a['cCurr'], (string)$b['cCurr']);
        });
        foreach ($groups as &$group) $group['balance'] = DecimalOutput::stored($group['balance']);
        unset($group);
        return ['currencies'=>$currencies, 'groups'=>$groups, 'primary'=>reset($groups)];
    }

    public static function code(array $currency, int|string $cid): string
    {
        if(($currency['cCID']??'')==='CCT')return (string)($currency['cCurrID']??'Token').' · #'.$cid;
        // exValue prefers its second argument: preserve currency ID, then display label.
        return (string)(StringHelper::exValue($currency['cCurr'] ?? '', $currency['cCurrID'] ?? '') ?: 'CUR'.$cid);
    }
}
