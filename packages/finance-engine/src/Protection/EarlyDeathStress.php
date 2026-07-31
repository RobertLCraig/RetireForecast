<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Protection;

use InvalidArgumentException;
use RetireForecast\FinanceEngine\Dto\CapitalReceipt;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Money\Money;

/**
 * **"What if one of you died soon?"** — the household transforms behind the protection-gap panel.
 *
 * A retirement plan for a couple is quietly a bet that both of them live roughly as long as the
 * table says. The first death is the sharpest single event in the whole projection: one State
 * Pension stops outright, a DB pension drops to its survivor's fraction (or to nothing), a salary
 * ends, and the survivor's spending falls by far less than their income does. The engine already
 * computes that cliff. What it never did was let a caller ASK for it at a chosen date, which is
 * what turns "your plan works" into "your plan works as long as you both live".
 *
 * Two transforms, both pure and both expressed through existing DTO fields rather than a new
 * projector mode — so a stressed path is the ordinary projection, run on a household that has been
 * told a different lifespan, and nothing about the tax, benefit or drawdown logic can diverge
 * between the two:
 *
 *  - {@see died()} — pin one person's modelled death to a calendar year (their age that year, via
 *    {@see LongevityAdjustment::fixedAge()}, which the representative-death-age rule already
 *    honours and clamps).
 *  - {@see withLifeCover()} — land a tax-free lump sum on the survivor in the death year, which is
 *    how life cover written in trust actually behaves. Sizing that sum is the whole question the
 *    protection gap asks, so it is a parameter, not a fixed figure.
 *
 * Deliberately NOT a Monte Carlo: this is a pinned "what if", the same discipline the care stress
 * follows (FCA-style: show the adverse case beside the central one, never average it in).
 */
final class EarlyDeathStress
{
    /** The label the injected cover carries on the cashflow ladder, so it is never an unexplained inflow. */
    public const COVER_LABEL = 'Life cover paid on death';

    /**
     * The same household with $personId's death pinned to $deathCalendarYear. Everyone else is
     * untouched, including any longevity what-if they already carry.
     *
     * The year is converted to an age here because age is the engine's currency and is never
     * stored: a person's modelled death is "at age N", derived from their DOB.
     */
    public static function died(Household $household, string $personId, int $deathCalendarYear): Household
    {
        if ($household->person($personId) === null) {
            throw new InvalidArgumentException("No person '{$personId}' in this household to model the death of.");
        }

        return $household->withPersons(array_map(
            static fn (Person $person): Person => $person->id === $personId
                ? $person->withLongevity(LongevityAdjustment::fixedAge($deathCalendarYear - (int) $person->dob->format('Y')))
                : $person,
            $household->persons,
        ));
    }

    /**
     * The same household with $amount of life cover paying out in $deathCalendarYear.
     *
     * Modelled as a {@see CapitalReceipt} because that is exactly what a life-insurance payout is
     * to the household: a documented, one-off, tax-free lump sum arriving from outside the plan.
     * Written in trust it falls outside the estate for Inheritance Tax, which the receipt's
     * treatment already matches (it is never taxed and never counted as means-test income; the
     * banked cash does raise the capital tariff from the following year, as in life).
     *
     * The receipt is owned by the person who is *paying*, since a receipt whose owner has died is
     * still received by the household — the surviving partner banks it. Any cover the household
     * already had is REPLACED, not added to: this is a solver input, and two overlapping answers
     * would compound instead of bracketing.
     */
    public static function withLifeCover(Household $household, string $deceasedId, int $deathCalendarYear, Money $amount): Household
    {
        $kept = array_values(array_filter(
            $household->capitalReceipts,
            static fn (CapitalReceipt $receipt): bool => $receipt->label !== self::COVER_LABEL,
        ));

        if ($amount->isPositive()) {
            $kept[] = new CapitalReceipt($deceasedId, self::COVER_LABEL, $amount, $deathCalendarYear);
        }

        return $household->withCapitalReceipts($kept);
    }
}
