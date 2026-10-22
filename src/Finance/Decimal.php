<?php

declare(strict_types=1);

namespace HScript\Finance;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Bounded decimal input; all arithmetic and comparisons remain exact. */
final class Decimal implements \Stringable, \JsonSerializable
{
    private function __construct(private readonly BigDecimal $value) {}

    public static function of(mixed $value): self
    {
        if ((!is_string($value) && !is_int($value))
            || !preg_match('/^-?\d{1,36}(?:\.\d{1,18})?$/D', (string)$value)) {
            throw new InvalidArgumentException('decimal_invalid');
        }
        return new self(BigDecimal::of($value));
    }

    public function plus(self $other): self { return new self($this->value->plus($other->value)); }
    public function minus(self $other): self { return new self($this->value->minus($other->value)); }
    public function multipliedBy(self $other): self { return new self($this->value->multipliedBy($other->value)); }
    public function dividedBy(self $other, int $scale, RoundingMode $rounding): self
    {
        self::assertScale($scale);
        return new self($this->value->dividedBy($other->value, $scale, $rounding));
    }
    public function rounded(int $scale, RoundingMode $rounding): self
    {
        self::assertScale($scale);
        return new self($this->value->toScale($scale, $rounding));
    }
    public function negated(): self { return new self($this->value->negated()); }
    public function compareTo(self $other): int { return $this->value->compareTo($other->value); }
    public function isZero(): bool { return $this->value->isZero(); }
    public function isNegative(): bool { return $this->value->isNegative(); }
    public function isInteger(): bool { return $this->value->isEqualTo($this->value->toScale(0, RoundingMode::Down)); }
    public function __toString(): string { return $this->value->toString(); }
    public function jsonSerialize(): string { return (string)$this; }

    private static function assertScale(int $scale): void
    {
        if ($scale < 0 || $scale > 36) throw new InvalidArgumentException('decimal_scale_invalid');
    }
}
