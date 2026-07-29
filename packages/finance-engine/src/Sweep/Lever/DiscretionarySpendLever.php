<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Sweep\Lever;

use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Sweep\LeverDirection;
use RetireForecast\FinanceEngine\Sweep\SweepInputs;
use RetireForecast\FinanceEngine\Sweep\SweepLever;

/**
 * Spending more (or less) on the things you CHOOSE: set the household's discretionary annual spend
 * to the swept value (in £), keeping the essential floor and everything else fixed.
 *
 * The counterpart of {@see EssentialSpendLever}, and the one that answers a different question.
 * Sweeping essentials asks "how little could we live on?"; sweeping discretionary asks **"how much
 * could we afford to enjoy?"** — the holiday/treats budget. Searching this lever for the point the
 * plan stops working turns the tool's usual output (here is what your plan does) into the one a
 * reader actually wants (here is what you could spend).
 *
 * Higher spend can only lower the chance the money lasts, so success is monotone decreasing —
 * which is what makes a bisection search valid. The change touches neither mortality nor the
 * return path, so common random numbers stay valid in a Monte Carlo sweep. The contingent-cost
 * markers (property / mortgage / employment) and the essential spending path are carried through
 * unchanged, so the lever never silently flattens a smile it did not set.
 */
final class DiscretionarySpendLever implements SweepLever
{
    public function apply(Household $household, ForecastSettings $settings, float $value): SweepInputs
    {
        $e = $household->expenseProfile;
        $profile = new ExpenseProfile(
            $e->essentialAnnualSpend,
            Money::fromPence((int) round($value * 100)),
            $e->survivorSpendFactor,
            $e->oneOffCosts,
            $e->propertyCosts,
            $e->employmentCosts,
            $e->mortgageCosts,
            essentialSpendPath: $e->essentialSpendPath,
        );

        return new SweepInputs(
            new Household(
                $household->name, $household->region, $household->persons, $profile,
                $household->pensions, $household->accounts, $household->incomeStreams,
                $household->primaryResidence, $household->relationshipStatus,
                $household->capitalReceipts, $household->realisedGainsAtStart,
            ),
            $settings,
        );
    }

    public function name(): string
    {
        return 'discretionary spend';
    }

    public function unit(): string
    {
        return '£';
    }

    public function direction(): LeverDirection
    {
        return LeverDirection::Decreasing;
    }
}
