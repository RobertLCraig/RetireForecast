<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Compliance\Interpretation;
use App\DecisionSupport\SustainableSpend;
use App\Forecast\AffordabilityAssessment;
use App\Models\Scenario;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use Tests\Support\BuilderStateFixture;
use Tests\TestCase;

/**
 * Board card 0025, AC #4. The engine judges an unfunded one-off lump apart from the recurring
 * budget, so `fullSpendAlwaysMet` stays true over it. No reader that says "fully funded" may
 * read that flag alone: each asks `ForecastResult::fullyFunded()`, which sees the lump.
 */
final class UnfundedOneOffReadersTest extends TestCase
{
    use RefreshDatabase;

    /** Recurring budget met every year, a £125,000 purchase gap unfunded in year 0. */
    private function forecast(int $unfundedPounds): ForecastResult
    {
        $year = new YearResult(
            yearIndex: 0, calendarYear: 2026, ages: ['p1' => 68], aliveCount: 1,
            grossIncome: Money::fromPounds(30_000), totalTax: Money::zero(), netIncome: Money::fromPounds(30_000),
            spendTarget: Money::fromPounds(30_000 + $unfundedPounds), essentialSpend: Money::fromPounds(20_000),
            shortfallFunded: Money::zero(), unmetSpend: Money::fromPounds($unfundedPounds), essentialsMet: true,
            liquidWealth: Money::fromPounds(50_000), pensionWealth: Money::zero(), propertyWealth: Money::fromPounds(300_000),
            unmetOneOffSpend: Money::fromPounds($unfundedPounds),
        );

        return new ForecastResult(
            years: [$year], essentialsAlwaysMet: true, fullSpendAlwaysMet: true, depletionCalendarYear: null,
            terminalTotalWealth: Money::fromPounds(350_000), terminalUsableWealth: Money::fromPounds(50_000),
            finalCalendarYear: 2026,
        );
    }

    public function test_the_afford_card_does_not_call_a_plan_with_an_unfunded_purchase_comfortable(): void
    {
        $household = new Household(
            'Unfunded', RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(20_000), Money::fromPounds(10_000), Percent::fromPercent(100)),
        );
        $plan = new Scenario;
        $plan->id = 1;
        $plan->name = 'Buy cheaper';
        $forecast = $this->forecast(125_000);

        [$card] = AffordabilityAssessment::cards([[
            'scenario' => $plan, 'variant' => 'buy_outright', 'forecast' => $forecast, 'careStress' => $forecast,
            'household' => $household, 'baseYear' => 2026, 'monthlyRent' => null, 'mc' => null,
        ]]);

        $this->assertNotSame('comfortable', $card['tier']);
        $this->assertStringNotContainsString('covers your full budget', $card['verdict']);
        $this->assertStringContainsString(Money::fromPounds(125_000)->format(), $card['verdict'], 'the verdict names the unfunded sum');
    }

    public function test_the_interpretation_does_not_say_full_spending_is_funded(): void
    {
        $lines = Interpretation::compareNarrative([
            ['name' => 'Buy cheaper', 'forecast' => $this->forecast(125_000)],
            ['name' => 'Stay put', 'forecast' => $this->forecast(0)],
        ]);

        $buy = implode(' ', array_filter($lines, static fn (string $l): bool => str_contains($l, 'Buy cheaper')));
        $this->assertStringNotContainsString('full spending is funded every year', $buy);
        $this->assertStringContainsString(Money::fromPounds(125_000)->format(), $buy);
    }

    public function test_sustainable_spend_reports_no_allowance_over_an_unfundable_one_off(): void
    {
        // A one-off far beyond anything the household owns: no level of restraint funds it, so
        // there is no spare budget to report, exactly as before the lump was judged apart.
        $state = BuilderStateFixture::full();
        $state['oneOffCosts'] = [['id' => 'oneoff1', 'atAge' => '80', 'amount' => '50000000', 'label' => 'Unaffordable purchase']];
        // A floor the pensions alone cover, so the drained savings never fail the essentials:
        // the lump is the ONLY thing that goes unfunded.
        $state['expenseLines'] = [
            ['id' => 'e1', 'label' => 'Essentials', 'amount' => '2000', 'category' => 'essential', 'savedAsAsset' => false],
        ];

        $scenario = new Scenario;
        $scenario->user_id = User::factory()->create()->id;
        $scenario->builder_state = $state;
        $scenario->projectFrom($state);
        $scenario->save();

        $this->assertNull(app(SustainableSpend::class)->forScenario($scenario));
    }
}
