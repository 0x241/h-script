<?php

declare(strict_types=1);

namespace HScript\Finance;

use Brick\Math\RoundingMode;

/** Percent precision is independent of the receiving currency's minor unit. */
final class Percentage
{
    private function __construct(private readonly Decimal $value) {}
    public static function of(mixed $value): self { return new self(Decimal::of($value)); }
    public function apply(Decimal $amount, int $scale, RoundingMode $rounding): Decimal
    {
        return $amount->multipliedBy($this->value)->dividedBy(Decimal::of(100), $scale, $rounding);
    }
    public function decimal(): Decimal { return $this->value; }
}
