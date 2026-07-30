<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use App\Forecast\ResultPresenter;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * THE STANDING RULE (Rob, 2026-07-30): **the model must never use a figure the user cannot see or
 * interrogate.** Where an input is left blank the engine supplies a default for itself, and a default
 * that silently moves the result is indistinguishable, to a reader, from a number we invented.
 *
 * Two such figures were found applying silently when this test was written — a bought home's upkeep
 * (1% of value a year) and the cost of moving (£2,000) — both of which move the plan by real money
 * with nothing on any screen to show for it.
 *
 * This test is the guard. Adding an engine-side default without disclosing it fails here.
 *
 * @see ResultPresenter::assumedFigures()
 */
final class AssumedFiguresDisclosureTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $housing
     * @return list<string> the assumed-figure disclosures a reader would see
     */
    private function disclosures(array $housing, string $currentRunningCosts = ''): array
    {
        $state = [
            'householdName' => 'Movers', 'region' => 'england_wales_ni', 'baseTaxYear' => '2026-27',
            'people' => [['id' => 'p1', 'dob' => '1955-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'pensions' => [['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230']],
            'accounts' => [['id' => 'a1', 'ownerId' => 'p1', 'type' => 'isa', 'balance' => '150000']],
            'expenseLines' => [['id' => 'e1', 'amount' => '18000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => true,
            'property' => ['currentValue' => '400000', 'ownership' => 'outright', 'runningCosts' => $currentRunningCosts],
            'housing' => $housing,
        ];

        $assembler = new HouseholdAssembler;
        $household = $assembler->household($state);
        $forecast = (new DeterministicForecaster(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi),
            new CohortLifeTable,
        ))->forecast($household, AssumptionSetLibrary::default(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));

        $notes = ResultPresenter::inputNotes($household, $forecast, $assembler->housingAction($housing));

        return array_values(array_map(
            static fn (array $n): string => $n['text'],
            array_filter($notes, static fn (array $n): bool => $n['kind'] === 'assumed_figure'),
        ));
    }

    public function test_an_assumed_upkeep_figure_is_disclosed_with_its_value(): void
    {
        // No running costs given for the home being bought => the engine assumes 1% of value a year.
        // The reader must be told the rate AND the resulting pounds.
        $disclosures = $this->disclosures(['salePrice' => '400000', 'buyPrice' => '150000', 'movingCosts' => '3000']);

        $this->assertCount(1, $disclosures);
        $expected = Money::fromPounds(150_000)
            ->applyRate(Percent::fromBasisPoints(HousingComparison::HOME_MAINTENANCE_RATE_BPS));

        $this->assertStringContainsString('1% of its value', $disclosures[0]);
        $this->assertStringContainsString($expected->format(), $disclosures[0], 'the disclosed pounds must be the figure actually used');
    }

    public function test_an_assumed_moving_cost_is_disclosed_with_its_value(): void
    {
        $disclosures = $this->disclosures([
            'salePrice' => '400000', 'buyPrice' => '150000', 'buyRunningCosts' => '3000',
        ]);

        $this->assertCount(1, $disclosures);
        $this->assertStringContainsString(
            Money::fromPence(HousingComparison::DEFAULT_MOVING_COSTS_PENCE)->format(),
            $disclosures[0],
            'the disclosed moving cost must be the constant the engine actually applies',
        );
    }

    public function test_both_defaults_are_disclosed_when_both_apply(): void
    {
        $disclosures = $this->disclosures(['salePrice' => '400000', 'buyPrice' => '150000']);

        $this->assertCount(2, $disclosures, 'every figure the engine supplied must be listed, not just the first');
    }

    public function test_nothing_is_claimed_as_assumed_when_the_user_gave_every_figure(): void
    {
        // No noise: a fully specified purchase discloses nothing, so the notes stay meaningful.
        $this->assertSame([], $this->disclosures([
            'salePrice' => '400000', 'buyPrice' => '150000',
            'buyRunningCosts' => '3000', 'movingCosts' => '3000',
        ]));
    }

    public function test_a_derived_upkeep_figure_is_not_reported_as_assumed(): void
    {
        // When the current home HAS running costs, the engine scales those by price rather than
        // assuming 1% — that is derived from the user's own figure, so it is not an invented number.
        $disclosures = $this->disclosures(
            ['salePrice' => '400000', 'buyPrice' => '150000', 'movingCosts' => '3000'],
            currentRunningCosts: '8000',
        );

        $this->assertSame([], $disclosures);
    }

    public function test_a_plan_that_never_buys_discloses_nothing(): void
    {
        // Both defaults only bite on a purchase; a stay-put or rent plan must not be given noise.
        $this->assertSame([], $this->disclosures(['salePrice' => '400000', 'annualRent' => '18000']));
    }

    public function test_the_disclosed_figures_are_read_from_the_engine_not_restated(): void
    {
        // Guards the drift this rule exists to prevent: if the engine's constant changed but the
        // disclosure did not, the reader would be shown a figure the model is not using. Because the
        // presenter reads the constant, moving the constant moves the disclosure.
        $disclosures = $this->disclosures(['salePrice' => '400000', 'buyPrice' => '250000', 'movingCosts' => '3000']);

        $onBiggerHome = Money::fromPounds(250_000)
            ->applyRate(Percent::fromBasisPoints(HousingComparison::HOME_MAINTENANCE_RATE_BPS));

        $this->assertStringContainsString($onBiggerHome->format(), $disclosures[0]);
        $this->assertStringNotContainsString('£1,500.00', $disclosures[0], 'the figure must track the home, not be hardcoded');
    }
}
