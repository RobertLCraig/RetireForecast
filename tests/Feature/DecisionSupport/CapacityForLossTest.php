<?php

declare(strict_types=1);

namespace Tests\Feature\DecisionSupport;

use App\DecisionSupport\CapacityForLoss;
use App\Forecast\ScenarioForecaster;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Money\RoundingMode;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Sweep\Lever\WealthFallLever;
use Tests\Support\BuilderStateFixture;
use Tests\TestCase;

/**
 * Capacity for loss: how far wealth can fall before the essential spending floor is breached.
 *
 * The properties that make the answer worth showing:
 *  1. it is a real **threshold** — the reported fall still meets the essentials and one percentage
 *     point more does not, which is what separates a searched answer from a plausible guess;
 *  2. the percentage and the cash figure are the **same quantity**, both measured against the
 *     project's one definition of total wealth (net of the mortgage) — so a reader can check the
 *     arithmetic and it cannot drift from the wealth line everything else shows;
 *  3. a plan already short of its essentials says so, and is not hidden: "no room at all" is the
 *     most important thing the panel can report, and it is a different message from "0% left";
 *  4. a plan whose essentials rest on income alone survives losing **everything**, and says so
 *     rather than reporting a number the search never reached.
 */
final class CapacityForLossTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $state */
    private function scenario(array $state): Scenario
    {
        $user = User::factory()->create();

        $scenario = new Scenario;
        $scenario->user_id = $user->id;
        $scenario->builder_state = $state;
        $scenario->projectFrom($state);
        $scenario->save();

        return $scenario;
    }

    /** The ISA the fixture's savings sit in, in pence — the liquid half of its total wealth. */
    private const ISA_PENCE = 600_000_00;

    /**
     * A long-retired couple whose State Pensions fall well short of their essential spending, so
     * savings are what actually pay the floor — the case this panel exists for. Both are past
     * State Pension age at the base year, so no year is short merely because a pension has not
     * started yet. Stay-put, so the housing variant is the plain household.
     *
     * @return array<string, mixed>
     */
    private function state(string $essentials = '30000', string $isa = '600000'): array
    {
        $state = BuilderStateFixture::full();
        $state['name'] = 'Capacity';
        $state['baseTaxYear'] = '2026-27';
        $state['variant'] = 'stay_put';
        $state['people'] = [
            ['id' => 'p1', 'name' => 'Alex', 'dob' => '1952-04-02', 'sex' => 'male', 'employmentStatus' => 'retired',
                'grossSalary' => '', 'salaryGrowth' => '', 'plannedRetirementAge' => '', 'niCategory' => ''],
            ['id' => 'p2', 'name' => 'Sam', 'dob' => '1953-11-20', 'sex' => 'female', 'employmentStatus' => 'retired',
                'grossSalary' => '', 'salaryGrowth' => '', 'plannedRetirementAge' => '', 'niCategory' => ''],
        ];
        $state['pensions'] = [
            ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230.25', 'qualifyingYears' => '', 'deferralWeeks' => '0'],
            ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '230.25', 'qualifyingYears' => '', 'deferralWeeks' => '0'],
        ];
        $state['accounts'] = [
            ['id' => 'acc1', 'ownerId' => 'p1', 'type' => 'isa', 'balance' => $isa, 'unrealisedGain' => '', 'yield' => ''],
        ];
        $state['incomeStreams'] = [];
        $state['capitalReceipts'] = [];
        $state['oneOffCosts'] = [];
        $state['expenseLines'] = [
            ['id' => 'ess1', 'label' => 'Essentials', 'amount' => $essentials, 'category' => 'essential', 'savedAsAsset' => false],
        ];

        return $state;
    }

    private function capacity(): CapacityForLoss
    {
        return app(CapacityForLoss::class);
    }

    /** Does this scenario still meet its essential floor every year after a fall of $percent? */
    private function essentialsHoldAfter(Scenario $scenario, int $percent): bool
    {
        $forecaster = app(ScenarioForecaster::class);
        ['household' => $household, 'settings' => $settings, 'assumptions' => $assumptions]
            = $forecaster->variantInputs($scenario);

        $fallen = (new WealthFallLever)->apply($household, $settings, $percent / 100);

        return (new DeterministicForecaster($forecaster->config($scenario), new CohortLifeTable))
            ->forecast($fallen->household, $assumptions, $fallen->settings)
            ->essentialsAlwaysMet;
    }

    public function test_the_reported_fall_is_a_threshold_not_a_guess(): void
    {
        $scenario = $this->scenario($this->state());
        $result = $this->capacity()->forScenario($scenario);

        $this->assertFalse($result['alreadyBreached'], 'this plan meets its essentials as it stands');
        $this->assertFalse($result['survivesTotalLoss'], 'a floor above the State Pensions cannot survive losing everything');
        $this->assertGreaterThan(0, $result['percent']);
        $this->assertLessThan(100, $result['percent']);

        $this->assertTrue(
            $this->essentialsHoldAfter($scenario, $result['percent']),
            'the reported fall must be one the projection actually survives',
        );
        $this->assertFalse(
            $this->essentialsHoldAfter($scenario, $result['percent'] + 1),
            'one point more must break the floor, or the answer is not the limit',
        );
    }

    public function test_the_percentage_and_the_cash_figure_are_the_same_quantity(): void
    {
        $result = $this->capacity()->forScenario($this->scenario($this->state()));

        $this->assertSame(
            $result['wealth']->applyRate(Percent::fromPercent($result['percent']), RoundingMode::Floor)->pence,
            $result['cash']->pence,
            'the pounds must be the reported percentage of the reported wealth',
        );
    }

    /**
     * The denominator is the project's one definition of total wealth — net of the mortgage — read
     * from the plan on display, not a figure assembled beside it. Here: the ISA plus the home's
     * equity after the loan on it.
     */
    public function test_the_wealth_it_is_measured_against_is_net_of_the_mortgage(): void
    {
        $scenario = $this->scenario($this->state());
        $result = $this->capacity()->forScenario($scenario);

        $forecaster = app(ScenarioForecaster::class);
        ['household' => $household, 'settings' => $settings] = $forecaster->variantInputs($scenario);

        $this->assertSame(WealthFallLever::baseWealth($household, $settings)->pence, $result['wealth']->pence);

        $home = $household->primaryResidence;
        $this->assertNotNull($home, 'the fixture owns a home, so the mortgage netting is exercised');
        $this->assertTrue($home->outstandingMortgage?->isPositive() ?? false);
        $this->assertLessThan(
            $home->currentValue->pence,
            $result['wealth']->pence - self::ISA_PENCE,
            'wealth counts the home net of what is owed on it, never its gross value',
        );
    }

    public function test_a_plan_already_short_of_its_essentials_says_so_rather_than_vanishing(): void
    {
        // A floor far beyond anything this household can fund: the essentials are breached before
        // a penny is lost. That is the most important thing the panel can say, so it is reported
        // as its own state, not hidden and not dressed up as a capacity of 0%.
        $result = $this->capacity()->forScenario($this->scenario($this->state(essentials: '400000', isa: '10000')));

        $this->assertTrue($result['alreadyBreached']);
        $this->assertSame(0, $result['percent']);
        $this->assertTrue($result['cash']->isZero());
        $this->assertFalse($result['survivesTotalLoss']);
        $this->assertTrue($result['wealth']->isPositive(), 'the wealth it would have been measured against is still reported');
    }

    public function test_a_floor_carried_by_income_alone_survives_losing_everything(): void
    {
        // Two State Pensions against a floor of £6,000 plus the home's £6,400 upkeep: the wealth
        // is not what pays for the essentials, so the plan holds even with nothing left.
        $result = $this->capacity()->forScenario($this->scenario($this->state(essentials: '6000', isa: '20000')));

        $this->assertTrue($result['survivesTotalLoss']);
        $this->assertFalse($result['alreadyBreached']);
        $this->assertSame(100, $result['percent']);
    }

    /**
     * The plan on display is resolved before it is stressed. A sell-and-rent plan is a different
     * household — no home, the proceeds invested, rent to pay — so its capacity is its own figure,
     * not the stay-put one relabelled. Reading a sell plan off the stay-put path is a live trap in
     * this codebase.
     */
    public function test_each_housing_strategy_is_stressed_as_the_plan_it_is(): void
    {
        $scenario = $this->scenario($this->state());

        $stayPut = $this->capacity()->forScenario($scenario, 'stay_put');
        $renting = $this->capacity()->forScenario($scenario, 'rent');

        $this->assertNotSame(
            $stayPut['wealth']->pence,
            $renting['wealth']->pence,
            'selling turns home equity into invested cash net of the costs of selling, so the two plans do not hold the same wealth',
        );
    }
}
