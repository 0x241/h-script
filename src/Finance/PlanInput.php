<?php

declare(strict_types=1);

namespace HScript\Finance;

use Brick\Math\Exception\MathException;
use InvalidArgumentException;
use OverflowException;

/** Monetary fields of the administrator plan form, with its existing signed rules. */
final class PlanInput
{
    private const FIELDS = [
        'pMin' => [6, 'min_wrong'], 'pMax' => [6, 'max_wrong'],
        'pMinAdd' => [6, 'minadd_wrong'], 'pPerc' => [2, 'perc_wrong'],
        'pReturn' => [2, 'perc2_wrong'], 'pBonus' => [2, 'bonus_wrong'],
        'pCompndMin' => [2, 'compndmin_wrong'], 'pCompndMax' => [2, 'compndmax_wrong'],
        'pClComis' => [2, 'clcomis_wrong'],
    ];

    public static function normalize(array $input): array
    {
        $amounts = [];
        foreach (self::FIELDS as $field => [$scale, $error]) {
            try {
                $amounts[$field] = AdministrativeDecimal::read($input[$field] ?? '', $field !== 'pMin' && $field !== 'pMax');
                $input[$field] = $scale === 6 ? StoredAmount::exact($amounts[$field]) : StoredPercentage::exact($amounts[$field]);
            } catch (InvalidArgumentException|OverflowException|MathException $e) {
                throw new InvalidArgumentException($error, 0, $e);
            }
        }
        if ($amounts['pMin']->compareTo(Decimal::of('0.01')) < 0) throw new InvalidArgumentException('min_wrong');
        if ($amounts['pMax']->compareTo($amounts['pMin']) <= 0) throw new InvalidArgumentException('max_wrong');
        $zero = Decimal::of(0); $hundred = Decimal::of(100);
        if ($amounts['pPerc']->compareTo($zero) <= 0 && $amounts['pReturn']->compareTo($zero) <= 0)
            throw new InvalidArgumentException('percent_required');
        foreach (['pCompndMin', 'pCompndMax', 'pClComis'] as $field) {
            if ($amounts[$field]->compareTo($zero) < 0 || $amounts[$field]->compareTo($hundred) > 0)
                throw new InvalidArgumentException(self::FIELDS[$field][1]);
        }
        if ($amounts['pCompndMax']->compareTo($amounts['pCompndMin']) < 0)
            throw new InvalidArgumentException('compndmax_wrong');
        return $input;
    }
}
