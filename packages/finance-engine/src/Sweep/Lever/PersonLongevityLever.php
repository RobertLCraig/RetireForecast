<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Sweep\Lever;

use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Sweep\LeverDirection;
use RetireForecast\FinanceEngine\Sweep\SweepInputs;
use RetireForecast\FinanceEngine\Sweep\SweepLever;

/**
 * Whose longevity binds: set ONE named person's lifespan what-if to live the swept number of years
 * longer (or fewer) than their cohort peer — a ± year offset on that person alone. Splitting the
 * combined "live 10 years longer" bump per person is the insight the combined lever hides: on a
 * survivor-cliff couple, extending the BETTER-provided partner's life can raise the chance the money
 * lasts (their pension keeps paying), while extending the SURVIVOR's life lowers it (more years to
 * fund from a thinner income after the first death). So success is NOT provably monotone in the
 * offset — {@see LeverDirection::Unknown} — and the sweep must report the first crossing and flag
 * that others may exist, never fit a single monotone crossing.
 *
 * The offset is applied AFTER the peer death is drawn ({@see LongevityMode::OffsetYears}: shift a
 * fresh peer draw), so it changes neither the number of mortality draws nor the return path — common
 * random numbers stay aligned across the grid even though the direction is Unknown (a fixed-age or
 * mortality-multiplier lever would consume a different number of draws and desync them; the offset
 * lever does not). Only the named person is touched; everyone else passes through unchanged.
 */
final class PersonLongevityLever implements SweepLever
{
    public function __construct(private readonly string $personId) {}

    public function apply(Household $household, ForecastSettings $settings, float $value): SweepInputs
    {
        $offset = (int) round($value);

        $persons = array_map(
            fn (Person $person): Person => $person->id === $this->personId
                ? $person->withLongevity(LongevityAdjustment::offsetYears($offset))
                : $person,
            $household->persons,
        );

        return new SweepInputs(
            $household->withPersons($persons),
            $settings,
        );
    }

    public function name(): string
    {
        return 'how long one of you lives';
    }

    public function unit(): string
    {
        return 'years';
    }

    public function direction(): LeverDirection
    {
        return LeverDirection::Unknown;
    }
}
