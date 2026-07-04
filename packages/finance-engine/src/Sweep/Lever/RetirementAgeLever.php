<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Sweep\Lever;

use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Sweep\LeverDirection;
use RetireForecast\FinanceEngine\Sweep\SweepInputs;
use RetireForecast\FinanceEngine\Sweep\SweepLever;

/**
 * Working later: set every currently-earning person's planned retirement age to the swept value
 * (clamped to a sane 50–80, matching the builder). Working longer only helps — more earnings and
 * pension contributions in, a shorter drawdown period — so success is monotone increasing, which
 * keeps common random numbers valid: the change does not alter mortality or the return path, only
 * how long earnings run. Retired members are untouched (they have no retirement age to move).
 */
final class RetirementAgeLever implements SweepLever
{
    private const MIN_AGE = 50;

    private const MAX_AGE = 80;

    public function apply(Household $household, ForecastSettings $settings, float $value): SweepInputs
    {
        $age = max(self::MIN_AGE, min(self::MAX_AGE, (int) round($value)));

        $persons = array_map(
            static fn (Person $person): Person => in_array($person->employmentStatus, [EmploymentStatus::Employed, EmploymentStatus::SelfEmployed], true)
                ? $person->withPlannedRetirementAge($age)
                : $person,
            $household->persons,
        );

        return new SweepInputs(
            new Household(
                $household->name, $household->region, $persons, $household->expenseProfile,
                $household->pensions, $household->accounts, $household->incomeStreams,
                $household->primaryResidence, $household->relationshipStatus,
            ),
            $settings,
        );
    }

    public function name(): string
    {
        return 'retirement age';
    }

    public function unit(): string
    {
        return 'years';
    }

    public function direction(): LeverDirection
    {
        return LeverDirection::Increasing;
    }
}
