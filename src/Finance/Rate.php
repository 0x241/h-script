<?php

declare(strict_types=1);

namespace HScript\Finance;

use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Positive rate; rounding is specified only at the conversion boundary. */
final class Rate
{
    private function __construct(private readonly Decimal $value) {}
    public static function of(mixed $value): self
    {
        $number = Decimal::of($value);
        if ($number->compareTo(Decimal::of(0)) <= 0) throw new InvalidArgumentException('rate_invalid');
        return new self($number);
    }
    public function multiply(Decimal $amount, int $scale, RoundingMode $rounding): Decimal
    {
        return $amount->multipliedBy($this->value)->rounded($scale, $rounding);
    }
    public function divide(Decimal $amount, int $scale, RoundingMode $rounding): Decimal
    {
        return $amount->dividedBy($this->value, $scale, $rounding);
    }
    public function decimal(): Decimal { return $this->value; }
}
