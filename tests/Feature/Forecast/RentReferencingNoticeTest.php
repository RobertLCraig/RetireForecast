<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Forecast\ResultPresenter;
use App\Forecast\ScenarioForecaster;
use App\Livewire\ScenarioResults;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Housing\Tenancy;
use RetireForecast\FinanceEngine\Money\Money;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * Board card 0031, the reader-facing half. The engine now flags a year whose income would fail a
 * letting agent's standard reference, and states what starting the tenancy costs on day one —
 * but a flag nobody is shown is the same as no flag. Both have to reach the rent result.
 */
final class RentReferencingNoticeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function variantForecast(Scenario $scenario, string $variant): ForecastResult
    {
        return app(ScenarioForecaster::class)->deterministicVariants($scenario)[$variant];
    }

    /**
     * AC #1. The rich fixture rents at £18,000 a year, so a standard reference asks for £45,000
     * of gross income. The working years clear it; the retired ones do not, and the cashflow the
     * reader reads the plan off must say which years and why.
     */
    public function test_the_rent_ladder_flags_the_years_that_would_fail_a_reference(): void
    {
        $scenario = ScenarioFixture::rich($this->user);
        $forecast = $this->variantForecast($scenario, 'rent');
        $ladder = ResultPresenter::ladder($forecast);

        $this->assertNotNull($ladder['rentReferencing'], 'a rent plan short of the referencing bar must be flagged');
        $this->assertGreaterThan(0, $ladder['rentReferencing']['years']);
        $this->assertSame(
            $ladder['rentReferencing']['firstYear'],
            min(array_map(
                static fn (array $r): int => $r['year'],
                array_filter($ladder['rows'], static fn (array $r): bool => $r['failsReference']),
            )),
            'the headline year must be the first row the table itself marks',
        );

        // The flag belongs to the year it is raised on: it quotes that year's own income, not a
        // figure restated from the inputs. The rent rises in real terms here, so the bar moves too.
        $flaggedYear = null;
        foreach ($forecast->years as $year) {
            if ($year->calendarYear === $ladder['rentReferencing']['firstYear']) {
                $flaggedYear = $year;
            }
        }
        $message = $ladder['rentReferencing']['message'];
        $this->assertNotNull($flaggedYear);
        $this->assertStringContainsString($flaggedYear->grossIncome->format(), $message);

        // AC #2: the alternatives and what rent in advance would tie up, in the engine's own words.
        $this->assertStringContainsString('guarantor', $message);
        $this->assertStringContainsString(Tenancy::GUARANTOR_INCOME_MULTIPLE.' times the monthly rent', $message);
        $this->assertStringContainsString(
            Tenancy::ADVANCE_MONTHS_MIN.' to '.Tenancy::ADVANCE_MONTHS_MAX.' months of it',
            $message,
        );
        $this->assertMatchesRegularExpression('/£[\d,]+\.\d\d of capital locked up/', $message);
    }

    /** A plan that keeps the home pays no rent, so neither notice applies to it. */
    public function test_a_plan_that_pays_no_rent_carries_neither_notice(): void
    {
        $ladder = ResultPresenter::ladder($this->variantForecast(ScenarioFixture::rich($this->user), 'stay_put'));

        $this->assertNull($ladder['rentReferencing']);
        $this->assertNull($ladder['tenancyUpFront']);
        $this->assertSame([], array_values(array_filter($ladder['rows'], static fn (array $r): bool => $r['failsReference'])));
    }

    /** AC #3. What the tenancy costs on day one reaches the ladder, with both figures in it. */
    public function test_the_rent_ladder_states_the_up_front_tenancy_cost(): void
    {
        $ladder = ResultPresenter::ladder($this->variantForecast(ScenarioFixture::rich($this->user), 'rent'));
        $rent = Money::fromPounds(18_000);

        $this->assertNotNull($ladder['tenancyUpFront']);
        $this->assertStringContainsString(Tenancy::deposit($rent)->format(), $ladder['tenancyUpFront']);
        $this->assertStringContainsString(Tenancy::upFrontCash($rent)->format(), $ladder['tenancyUpFront']);
    }

    /**
     * AC #3, the standing rule. The deposit is a figure the engine supplied for itself and it
     * moves the plan, so it must appear in the assumed-figure disclosure the audit polices —
     * quoted from the engine, never restated.
     */
    public function test_the_deposit_is_disclosed_as_a_figure_the_engine_supplied(): void
    {
        $scenario = ScenarioFixture::rich($this->user);
        $forecaster = app(ScenarioForecaster::class);

        $disclosed = ResultPresenter::assumedFigures(
            $scenario->toHousehold(),
            null,
            $this->variantForecast($scenario, 'rent'),
            'rent',
            $forecaster->assumptions($scenario),
        );

        $deposit = Tenancy::deposit(Money::fromPounds(18_000))->format();
        $this->assertNotEmpty(array_filter($disclosed, static fn (string $d): bool => str_contains($d, $deposit)));
    }

    /** Both notices reach the screen the reader actually reads the rent plan off. */
    public function test_the_results_page_shows_both_notices_on_a_rent_plan(): void
    {
        Livewire::test(ScenarioResults::class, ['scenario' => ScenarioFixture::rich($this->user)])
            ->assertSee('Renting has to be agreed as well as afforded')
            ->assertSee('standard reference', escape: false)
            ->assertSee('guarantor', escape: false)
            ->assertSee(Tenancy::deposit(Money::fromPounds(18_000))->format(), escape: false);
    }
}
