<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Iht;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * The Inheritance Tax an entire forecast path incurs across the household's deaths, in
 * REAL (today's money) terms — the projector computes each death's IHT in nominal pounds
 * at the death year (so the frozen nil-rate bands bite against the grown estate, i.e. real
 * fiscal drag), then deflates the result back to today's money like every other figure.
 *
 * $firstDeath is the earlier death of a two-person household (null for a single person, and
 * for a married couple it is the spousally-exempt £0 first death shown for completeness).
 * $secondDeath is the final death, where the estate passes to descendants — the death that
 * actually incurs IHT for a married couple. $total is $firstDeath + $secondDeath tax.
 */
final class IhtOutcome
{
    public function __construct(
        public readonly ?IhtResult $firstDeath,
        public readonly IhtResult $secondDeath,
        public readonly Money $total,
        /**
         * The BENEFICIARY's income tax across both deaths on the unused pension pots they inherit
         * (board card 0057). Kept apart from $total, which is Inheritance Tax: this charge falls on
         * somebody else, in later years, and adding the two together would misstate both. Shown
         * beside it, because a reader comparing spending a pot against preserving it needs the
         * whole cost of preserving it and not half of it.
         */
        public readonly Money $beneficiaryIncomeTax,
    ) {}
}
