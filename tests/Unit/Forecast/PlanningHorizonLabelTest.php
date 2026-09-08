<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\ResultPresenter;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Mortality\PlanningHorizon;

/**
 * Board card 0061, criterion 3. Printing that a plan "lasts for life" against a median lifespan
 * is the single most misleading string this tool can produce: a median is a coin flip, so the
 * plan the reader is being reassured about is one roughly half of households outlive. Wherever a
 * figure from the single deterministic path is shown, the odds behind its lifespan go with it.
 */
final class PlanningHorizonLabelTest extends TestCase
{
    private function basis(PlanningHorizon $horizon): string
    {
        return ResultPresenter::planningHorizonBasis(
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', planningHorizon: $horizon),
        );
    }

    public function test_a_median_lifespan_is_labelled_as_roughly_even_odds(): void
    {
        $basis = $this->basis(PlanningHorizon::P50);

        $this->assertStringContainsString('roughly even odds', $basis);
        $this->assertStringNotContainsString('for life', $basis, 'a coin-flip lifespan is never a plan that lasts for life');
    }

    public function test_every_horizon_states_the_odds_it_leaves(): void
    {
        foreach (PlanningHorizon::cases() as $horizon) {
            $this->assertStringContainsString($horizon->oddsPhrase(), $this->basis($horizon));
            $this->assertStringNotContainsString('for life', $this->basis($horizon));
        }
    }

    public function test_an_unstated_horizon_reads_as_the_engines_own_default(): void
    {
        // A run predating the setting has to be described as what it actually ran on, not left
        // to read as the median it used to be.
        $this->assertSame(
            $this->basis(PlanningHorizon::DEFAULT),
            ResultPresenter::planningHorizonBasis(null),
        );
    }
}
