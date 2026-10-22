<?php

declare(strict_types=1);

namespace HScript\Finance;

use Brick\Math\RoundingMode;
use InvalidArgumentException;
use OverflowException;

/** Currency identity and scale are part of the value, never inferred by math. */
final class Money implements \Stringable, \JsonSerializable
{
    private function __construct(
        private readonly Decimal $value,
        public readonly string $currency,
        public readonly int $scale,
    ) {
        if (!preg_match('/^[A-Za-z0-9:_-]{1,32}$/D', $currency) || $scale < 0 || $scale > 8)
            throw new InvalidArgumentException('money_currency_invalid');
        if ($value->compareTo(Decimal::of('999999999999.99999999')) > 0
            || $value->compareTo(Decimal::of('-999999999999.99999999')) < 0)
            throw new OverflowException('money_overflow');
    }

    public static function of(mixed $value, string $currency, int $scale, RoundingMode $rounding): self
    {
        return self::fromDecimal(Decimal::of($value), $currency, $scale, $rounding);
    }

    public static function fromDecimal(Decimal $value, string $currency, int $scale, RoundingMode $rounding): self
    {
        if ($scale < 0 || $scale > 8) throw new InvalidArgumentException('money_scale_invalid');
        return new self($value->rounded($scale, $rounding), $currency, $scale);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->value->plus($other->value), $this->currency, $this->scale);
    }
    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->value->minus($other->value), $this->currency, $this->scale);
    }
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);
        return $this->value->compareTo($other->value);
    }
    public function decimal(): Decimal { return $this->value; }
    public function __toString(): string { return (string)$this->value; }
    public function jsonSerialize(): string { return (string)$this; }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency || $this->scale !== $other->scale)
            throw new InvalidArgumentException('money_currency_mismatch');
    }
}
