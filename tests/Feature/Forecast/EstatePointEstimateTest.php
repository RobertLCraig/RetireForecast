<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Forecast\HouseholdAssembler;
use App\Forecast\ResultPresenter;
use App\Livewire\ScenarioCompare;
use App\Livewire\ScenarioResults;
use App\Models\Result;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * Board card 0058. The estate was a ONE-PATH figure reported to the pound and sat beside a
 * ten-thousand-path probability with nothing saying which was which, labelled "everything
 * you're modelled to leave" and stated gross of probate, the beneficiary's income tax and
 * any care debt. Where a rolled-up loan has passed the property value the point estimate
 * hides the mechanism entirely: the reader sees a number and reads it as the home minus
 * some cost, when the home is gone.
 */
class EstatePointEstimateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * #1 — the estate is shown as the SPREAD across the simulated paths, not one figure.
     * The presenter carries the band, and the estate panel renders it beside the single-path
     * number so the two can never be read as the same kind of measure.
     */
    public function test_the_estate_is_reported_as_a_range_across_the_simulated_paths(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $built = ResultPresenter::build(
            collect(['stay_put' => $this->resultWithTerminalBand(300_000)]),
            'stay_put',
        );

        $this->assertNotNull($built['estateRange'], 'a run reports the terminal-estate band');
        $this->assertSame(250_000, $built['estateRange']['p10']);
        $this->assertSame(300_000, $built['estateRange']['p50']);
        $this->assertSame(350_000, $built['estateRange']['p90']);

        // And it reaches the estate panel on the page, next to the deterministic figure.
        Livewire::test(ScenarioResults::class, ['scenario' => ScenarioFixture::rich($user, ['ihtModelled' => true])])
            ->set('previewPaths', 30)
            ->call('preview')
            ->assertSee('Across your simulated futures the wealth you leave ranges from');
    }

    /**
     * #2 — the Compare page puts a single-path table above a simulated one. Each surface says
     * which it is, or a reader compares a figure with one path behind it against one with ten
     * thousand and has nothing on the screen telling them so.
     */
    public function test_the_comparison_labels_which_figures_are_one_path_and_which_are_simulated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(ScenarioCompare::class, ['scenario' => ScenarioFixture::rich($user)])
            ->assertSee('every figure in this table comes from one central projection')
            ->assertSee('measured across all the simulated futures');
    }

    /**
     * #3 — once the rolled-up balance passes the property value the no-negative-equity floor
     * binds: terminal wealth stops moving and the property is fully consumed. Say so in plain
     * words, because the number alone reads as the home minus some cost.
     */
    public function test_a_rolled_up_loan_that_eats_the_home_says_the_lender_takes_the_property(): void
    {
        $notes = $this->notes($this->rollUpState());
        $kinds = array_column($notes, 'kind');
        $this->assertContains('lifetime_mortgage_rollup', $kinds);

        $text = $notes[array_search('lifetime_mortgage_rollup', $kinds, true)]['text'];
        $this->assertStringContainsString('the lender takes the property', $text);
        $this->assertStringContainsString('inherit only the money outside it', $text);
    }

    /**
     * #4 — the estate figure is stated exactly and gross of the things that come off it first.
     * The panel has to caveat probate cost and delay, the beneficiary's income tax and any care
     * debt, or the exactness is doing the reader harm.
     */
    public function test_the_estate_figure_is_caveated_for_probate_beneficiary_tax_and_care_debt(): void
    {
        $forecast = $this->forecast($this->rollUpState());
        $caveats = implode(' ', ResultPresenter::estateCaveats($forecast));

        $this->assertStringContainsString('probate', $caveats);
        $this->assertStringContainsString('their own income tax on every pound they draw', $caveats);
        $this->assertStringContainsString('care', $caveats);

        $user = User::factory()->create();
        $this->actingAs($user);
        Livewire::test(ScenarioResults::class, ['scenario' => ScenarioFixture::rich($user, ['ihtModelled' => true])])
            ->assertSee('What comes off this before anybody inherits');
    }

    /** A completed run whose terminal-wealth band is a known £50,000 either side of the median. */
    private function resultWithTerminalBand(int $p50): Result
    {
        $band = [
            'p10' => Money::fromPounds($p50 - 50_000),
            'p25' => Money::fromPounds($p50 - 20_000),
            'p50' => Money::fromPounds($p50),
            'p75' => Money::fromPounds($p50 + 20_000),
            'p90' => Money::fromPounds($p50 + 50_000),
        ];

        $sim = new SimulationResult(
            nPaths: 500,
            seed: 1,
            successProbabilityEssentials: 0.9,
            successProbabilityFullSpend: 0.8,
            depletionRate: 0.1,
            medianDepletionYear: null,
            terminalWealthPercentiles: $band,
            fanChart: [['calendarYear' => 2026, 'paths' => 500, ...$band]],
            usableWealthPercentiles: $band,
            usableFanChart: [['calendarYear' => 2026, 'paths' => 500, ...$band]],
        );

        return (new Result(['variant' => 'stay_put']))->setSimulationResult($sim);
    }

    /**
     * A lone homeowner on a 7.5% roll-up whose balance is already most of the house: over a
     * projection this long the compounding passes the property value, which is exactly the
     * state the point estimate hides.
     *
     * @return array<string, mixed>
     */
    private function rollUpState(): array
    {
        return [
            'householdName' => 'Rolled up', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'name' => 'Pat', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'pensions' => [['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230']],
            'expenseLines' => [['id' => 'e1', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => true,
            'property' => [
                'currentValue' => '250000', 'ownership' => 'mortgaged', 'outstandingMortgage' => '200000',
                'mortgageRollUpRate' => '7.5',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array{kind: string, text: string}>
     */
    private function notes(array $state): array
    {
        $assembler = new HouseholdAssembler;

        return ResultPresenter::inputNotes(
            $assembler->household($state),
            $this->forecast($state),
            $assembler->housingAction($state['housing'] ?? []),
        );
    }

    /** @param  array<string, mixed>  $state */
    private function forecast(array $state): ForecastResult
    {
        return (new DeterministicForecaster(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi),
            new CohortLifeTable,
        ))->forecast(
            (new HouseholdAssembler)->household($state),
            AssumptionSetLibrary::default(),
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
        );
    }
}
