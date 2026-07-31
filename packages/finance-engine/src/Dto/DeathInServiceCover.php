<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use InvalidArgumentException;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * Employer death-in-service (group life) cover on one working person: a lump sum the scheme
 * pays if they die **while still in employment**.
 *
 * It is the cheapest thing that can close a survivor cliff — most employed people hold it,
 * most forget they do, and it costs the household nothing. It is also a real cliff-edge, which
 * is the reason it is modelled as cover-while-employed rather than as a flat asset: it **ceases
 * at retirement**, so the protection a household is relying on disappears in exactly the years
 * the survivor has the longest left to fund.
 *
 * Two ways to state it, because schemes state it both ways:
 *  - {@see multipleOfSalary()} — "4x salary", the common form. Sized on the member's salary in
 *    the year of death, so it keeps pace with pay as the projection grows it.
 *  - {@see fixedSum()} — a stated sum assured. **Fixed nominal**, so it erodes in real terms
 *    exactly as a fixed sum assured does in life; it is not inflated.
 *
 * The multiple is a {@see Percent} (4x salary = 400%) so it stays exact integer basis points
 * under the engine's no-floats rule, rather than a float multiplier that could drift.
 *
 * How the payout is taxed is the projector's job, not this DTO's: see
 * PathProjector::collectDeathInServiceBenefit(). (Named in plain text on purpose — Pint's
 * fully_qualified_strict_types fixer turns a {@see} into a real `use`, which would give a DTO a
 * dependency on the Forecast layer that exists only to satisfy a comment.)
 */
final class DeathInServiceCover
{
    public function __construct(
        /** Cover as a multiple of the member's annual salary (400% = 4x); null if a fixed sum. */
        public readonly ?Percent $salaryMultiple,
        /** Cover as a stated sum assured, fixed in nominal terms; null if a salary multiple. */
        public readonly ?Money $fixedSum,
    ) {
        if (($salaryMultiple === null) === ($fixedSum === null)) {
            throw new InvalidArgumentException(
                'Death-in-service cover is either a multiple of salary or a fixed sum assured, not both and not neither.'
            );
        }
    }

    /** Cover stated as a multiple of salary, e.g. `Percent::fromPercent(400)` for 4x salary. */
    public static function multipleOfSalary(Percent $multiple): self
    {
        return new self($multiple, null);
    }

    /** Cover stated as a fixed sum assured (nominal — it does not rise with pay or prices). */
    public static function fixedSum(Money $sum): self
    {
        return new self(null, $sum);
    }

    /**
     * The sum payable, given the member's annual salary in the year of death (nominal pence, the
     * full-year figure — cover is a multiple of contractual annual salary, not of what a part-year
     * of work actually paid). A fixed sum ignores the salary by definition.
     */
    public function amountAt(Money $annualSalary): Money
    {
        if ($this->fixedSum !== null) {
            return $this->fixedSum;
        }

        return Money::fromPence((int) round($annualSalary->pence * $this->salaryMultiple->asFraction()));
    }

    /** How the cover is stated, for disclosure (e.g. "4x salary" / "£150,000"). */
    public function describe(): string
    {
        if ($this->fixedSum !== null) {
            return $this->fixedSum->format();
        }

        return rtrim(rtrim(number_format($this->salaryMultiple->asFraction(), 2), '0'), '.').'x salary';
    }
}
