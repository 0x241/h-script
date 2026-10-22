<?php

declare(strict_types=1);

namespace HScript\Finance;

use Brick\Math\Exception\MathException;
use InvalidArgumentException;
use OverflowException;

/** The currency form's saved monetary fields; existing signed policy remains. */
final class CurrencyInput
{
    public static function normalize(array $input): array
    {
        $fields = ['cCASHOUTLimit'=>[13,6], 'cEXIn'=>[10,6], 'cEXOut'=>[10,6]];
        foreach (['CASHIN','CASHOUT','EX','TR'] as $operation) {
            foreach (['Min','Max','ComisMin','ComisMax'] as $suffix) $fields['c'.$operation.$suffix] = [13,6];
            $fields['c'.$operation.'Comis'] = [6,2];
        }
        foreach ($fields as $field=>[$precision,$scale]) {
            try { $input[$field] = AdministrativeDecimal::stored(AdministrativeDecimal::read($input[$field] ?? ''), $precision, $scale); }
            catch (InvalidArgumentException|OverflowException|MathException $e) {
                throw new InvalidArgumentException('currency_decimal_invalid', 0, $e);
            }
        }
        return $input;
    }
}
