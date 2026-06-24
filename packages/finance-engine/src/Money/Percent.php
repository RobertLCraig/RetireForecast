<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Money;

/**
 * An exact percentage rate, stored as integer basis points (1bp = 0.01%).
 *
 * Every UK statutory tax rate is expressible to at most two decimal places of a
 * percent (e.g. 8.75%, 20%, 39.35%), so basis points represent them exactly with
 * no float drift. Stochastic investment returns are NOT modelled with this type:
 * those live as floats in the Monte Carlo return layer.
 */
final class Percent
{
    private function __construct(public readonly int $basisPoints) {}

    public static function fromPercent(int|float $percent): self
    {
        return new self((int) round($percent * 100));
    }

    public static function fromBasisPoints(int $basisPoints): self
    {
        return new self($basisPoints);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /** The rate as a decimal fraction, e.g. 0.0875 for 8.75%. For display/Monte Carlo only. */
    public function asFraction(): float
    {
        return $this->basisPoints / 10_000;
    }

    /** The rate as a percentage number, e.g. 8.75 for 8.75%. For display only. */
    public function asPercent(): float
    {
        return $this->basisPoints / 100;
    }
}
