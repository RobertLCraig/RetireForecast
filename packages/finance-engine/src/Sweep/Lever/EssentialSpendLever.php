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
 * Spending less: set the household's essential annual spend to the swept value (in £), keeping
 * every other part of the profile. Higher spend can only lower the chance the money lasts, so
 * success is monotone decreasing — and the change touches neither mortality nor the return path,
 * so common random numbers stay valid. The contingent-cost markers (property / mortgage /
 * employment) are carried through unchanged.
 */
final class EssentialSpendLever implements SweepLever
{
    public function apply(Household $household, ForecastSettings $settings, float $value): SweepInputs
    {
        $e = $household->expenseProfile;
        // Set the essential floor to a flat swept value; carry the discretionary path (its smile,
        // if any) through unchanged so the lever never silently flattens it.
        $profile = new ExpenseProfile(
            Money::fromPence((int) round($value * 100)),
            $e->discretionaryAnnualSpend,
            $e->survivorSpendFactor,
            $e->oneOffCosts,
            $e->propertyCosts,
            $e->employmentCosts,
            $e->mortgageCosts,
            discretionarySpendPath: $e->discretionarySpendPath,
        );

        return new SweepInputs(
            new Household(
                $household->name, $household->region, $household->persons, $profile,
                $household->pensions, $household->accounts, $household->incomeStreams,
                $household->primaryResidence, $household->relationshipStatus,
            ),
            $settings,
        );
    }

    public function name(): string
    {
        return 'essential spend';
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
