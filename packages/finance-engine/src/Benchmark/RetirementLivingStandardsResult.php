<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Benchmark;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * The outcome of comparing a household's annual spend to the PLSA Retirement Living
 * Standards: the three tier budgets for the household's composition, optionally with a
 * spend figure classified against them.
 *
 * $tierReached is the highest standard the spend meets ({@see RetirementLivingStandards::TIERS}),
 * or null when the spend is below the Minimum. It is a neutral fact — which yardstick the
 * spend lands on — never a judgement that the spend is too low or high.
 */
final class RetirementLivingStandardsResult
{
    /**
     * @param  array<string, Money>  $tiers  keyed by {@see RetirementLivingStandards::TIERS}
     */
    public function __construct(
        public readonly bool $couple,
        public readonly bool $london,
        public readonly array $tiers,
        public readonly ?Money $annualSpend = null,
        public readonly ?string $tierReached = null,
    ) {}

    /** A copy classifying $annualSpend against the tiers. */
    public function withSpend(Money $annualSpend): self
    {
        $reached = null;
        foreach (RetirementLivingStandards::TIERS as $tier) {
            if ($annualSpend->greaterThanOrEqual($this->tiers[$tier])) {
                $reached = $tier;
            }
        }

        return new self($this->couple, $this->london, $this->tiers, $annualSpend, $reached);
    }

    public function tier(string $tier): Money
    {
        return $this->tiers[$tier];
    }

    /** The spend meets or exceeds this tier's budget. */
    public function meets(string $tier): bool
    {
        return $this->annualSpend !== null && $this->annualSpend->greaterThanOrEqual($this->tiers[$tier]);
    }

    /** The spend is below the Minimum standard. */
    public function belowMinimum(): bool
    {
        return $this->annualSpend !== null && $this->tierReached === null;
    }

    /** The next standard up from the one reached, or null if already at Comfortable / unclassified. */
    public function nextTier(): ?string
    {
        $tiers = RetirementLivingStandards::TIERS;

        if ($this->annualSpend === null) {
            return null;
        }

        // Below the Minimum: the next standard to reach is the Minimum itself.
        if ($this->tierReached === null) {
            return $tiers[0];
        }

        $position = array_search($this->tierReached, $tiers, true);

        return $tiers[$position + 1] ?? null;
    }

    /** How much more annual spend would reach {@see nextTier()}, or null if there is none. */
    public function gapToNextTier(): ?Money
    {
        $next = $this->nextTier();
        if ($next === null || $this->annualSpend === null) {
            return null;
        }

        return $this->tiers[$next]->minus($this->annualSpend)->minZero();
    }
}
