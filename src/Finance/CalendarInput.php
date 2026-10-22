<?php

declare(strict_types=1);

namespace HScript\Finance;

use Brick\Math\Exception\MathException;
use InvalidArgumentException;
use OverflowException;

final class CalendarInput
{
    public static function percentage(mixed $value): string
    {
        try {
            $percentage = AdministrativeDecimal::read($value);
            if ($percentage->isNegative()) throw new InvalidArgumentException('calendar_percentage_negative');
            return StoredPercentage::exact($percentage);
        } catch (InvalidArgumentException|OverflowException|MathException $e) {
            throw new InvalidArgumentException('perc_wrong', 0, $e);
        }
    }
}
