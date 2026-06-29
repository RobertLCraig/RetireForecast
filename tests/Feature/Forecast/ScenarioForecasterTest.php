<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Forecast\ScenarioForecaster;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

class ScenarioForecasterTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): Scenario
    {
        return ScenarioFixture::rich(User::factory()->create());
    }

    public function test_the_deterministic_forecast_runs_for_a_persisted_scenario(): void
    {
        $result = (new ScenarioForecaster)->deterministic($this->scenario());

        $this->assertNotEmpty($result->years);
        $this->assertGreaterThanOrEqual(2026, $result->finalCalendarYear);
    }

    public function test_the_monte_carlo_run_records_its_seed_and_bounded_probabilities(): void
    {
        $result = (new ScenarioForecaster)->simulate($this->scenario(), nPaths: 50, seed: 7);

        $this->assertSame(50, $result->nPaths);
        $this->assertSame(7, $result->seed);
        $this->assertGreaterThanOrEqual(0.0, $result->successProbabilityEssentials);
        $this->assertLessThanOrEqual(1.0, $result->successProbabilityEssentials);
    }

    public function test_deterministic_variants_apply_the_housing_transforms_per_strategy(): void
    {
        $scenario = $this->scenario();
        $forecaster = new ScenarioForecaster;

        $variants = $forecaster->deterministicVariants($scenario);
        $this->assertSame(['stay_put', 'buy_outright', 'rent'], array_keys($variants));

        // stay_put is the raw household as entered — byte-identical to deterministic() (the
        // single source: variantInputs()['stay_put'] is the unchanged household + settings).
        $stay = $variants['stay_put'];
        $raw = $forecaster->deterministic($scenario);
        $this->assertSame($raw->finalCalendarYear, $stay->finalCalendarYear);
        $this->assertSame($raw->years[0]->totalWealth->pence, $stay->years[0]->totalWealth->pence);

        // Sell & rent owns no home: property wealth is zero in every year, so usable == total.
        $rent = $variants['rent'];
        foreach ($rent->years as $year) {
            $this->assertSame(0, $year->propertyWealth->pence, "rent kept property wealth in {$year->calendarYear}");
        }

        // Staying put keeps the home as an (illiquid) floor; selling frees its equity into
        // investments — so the sell variant carries more spendable (liquid) wealth from year 0.
        $this->assertGreaterThan(0, $stay->years[0]->propertyWealth->pence);
        $this->assertGreaterThan(
            $stay->years[0]->liquidWealth->pence,
            $rent->years[0]->liquidWealth->pence,
        );

        // Buying a cheaper home (£320k) leaves less in the home than staying put (£525k).
        $this->assertGreaterThan(0, $variants['buy_outright']->years[0]->propertyWealth->pence);
        $this->assertLessThan(
            $stay->years[0]->propertyWealth->pence,
            $variants['buy_outright']->years[0]->propertyWealth->pence,
        );
    }

    public function test_buy_vs_rent_returns_all_three_variants_and_is_reproducible(): void
    {
        $scenario = $this->scenario();
        $forecaster = new ScenarioForecaster;

        $a = $forecaster->compareHousing($scenario, nPaths: 50, seed: 42);
        $b = $forecaster->compareHousing($scenario, nPaths: 50, seed: 42);

        $this->assertSame(['stay_put', 'buy_outright', 'rent'], array_keys($a));

        // Identical seed gives byte-identical aggregates (the golden-master property).
        foreach (array_keys($a) as $variant) {
            $this->assertSame(
                $a[$variant]->terminalWealthPercentiles['p50']->pence,
                $b[$variant]->terminalWealthPercentiles['p50']->pence,
                "Variant {$variant} was not reproducible",
            );
        }
    }
}
