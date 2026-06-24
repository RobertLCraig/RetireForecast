<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Money;

use InvalidArgumentException;
use Stringable;

/**
 * An immutable money amount held as integer pence.
 *
 * No PHP float ever participates in money arithmetic. A 64-bit int holds values
 * up to ~9.2e16 pounds, far beyond any household balance sheet, so overflow is
 * not a practical concern. Currency is carried for safety but only GBP is used.
 */
final class Money implements Stringable
{
    private function __construct(
        public readonly int $pence,
        public readonly string $currency = 'GBP',
    ) {
    }

    public static function fromPence(int $pence, string $currency = 'GBP'): self
    {
        return new self($pence, $currency);
    }

    public static function fromPounds(int $pounds, string $currency = 'GBP'): self
    {
        return new self($pounds * 100, $currency);
    }

    /** Build from whole pounds plus a 0-99 pence component, preserving sign. */
    public static function of(int $pounds, int $pence = 0, string $currency = 'GBP'): self
    {
        if ($pence < 0 || $pence > 99) {
            throw new InvalidArgumentException('Pence component must be between 0 and 99.');
        }

        $sign = $pounds < 0 ? -1 : 1;

        return new self($pounds * 100 + $sign * $pence, $currency);
    }

    public static function zero(string $currency = 'GBP'): self
    {
        return new self(0, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->pence + $other->pence, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->pence - $other->pence, $this->currency);
    }

    public function times(int $factor): self
    {
        return new self($this->pence * $factor, $this->currency);
    }

    /** Apply a rate to this amount (e.g. tax due on income), rounding the pence result. */
    public function applyRate(Percent $rate, RoundingMode $mode = RoundingMode::HalfUp): self
    {
        return new self(
            IntMath::divRound($this->pence * $rate->basisPoints, 10_000, $mode),
            $this->currency,
        );
    }

    public function negated(): self
    {
        return new self(-$this->pence, $this->currency);
    }

    public function abs(): self
    {
        return new self(abs($this->pence), $this->currency);
    }

    /** This amount, but never below zero. */
    public function minZero(): self
    {
        return $this->pence < 0 ? self::zero($this->currency) : $this;
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->pence <=> $other->pence;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->pence === $other->pence;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function lessThanOrEqual(self $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    public function isZero(): bool
    {
        return $this->pence === 0;
    }

    public function isPositive(): bool
    {
        return $this->pence > 0;
    }

    public function isNegative(): bool
    {
        return $this->pence < 0;
    }

    public static function min(self $a, self $b): self
    {
        return $a->lessThanOrEqual($b) ? $a : $b;
    }

    public static function max(self $a, self $b): self
    {
        return $a->greaterThanOrEqual($b) ? $a : $b;
    }

    /** Plain decimal string, e.g. "1234.56" or "-5.00". No grouping, no symbol. */
    public function toDecimal(): string
    {
        $sign = $this->pence < 0 ? '-' : '';
        $abs = abs($this->pence);

        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Human-friendly, e.g. "£1,234.56". */
    public function format(): string
    {
        $sign = $this->pence < 0 ? '-' : '';
        $abs = abs($this->pence);

        return $sign . '£' . number_format(intdiv($abs, 100)) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}.");
        }
    }
}
