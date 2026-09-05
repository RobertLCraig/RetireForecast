<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use App\Forecast\ResultPresenter;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Card 0033, criterion 3. A running cost for the home being bought can be ENTERED, ASSUMED (1% of
 * value, already disclosed) or COMPUTED: where the current home has running costs of its own the
 * engine scales them by the two prices, and that third case said nothing at all. A figure the model
 * worked out for itself is not user input, and on a screen it reads exactly like one, so a reader
 * had no way to know a number they never typed was being charged for the life of the plan.
 *
 * It is a computed figure and not an assumed one, so it carries its own note kind: the rule that
 * produced it is stated, not just the pounds it produced.
 */
final class ComputedRunningCostsDisclosureTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $housing
     * @return list<string> the computed-figure disclosures a reader would see
     */
    private function computed(array $housing, string $currentRunningCosts = ''): array
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
            array_filter($notes, static fn (array $n): bool => $n['kind'] === 'computed_figure'),
        ));
    }

    public function test_a_scaled_purchase_running_cost_is_disclosed_with_the_rule_that_produced_it(): void
    {
        // £8,000 of upkeep on a £400,000 home, scaled to a £150,000 purchase, is £3,000 a year
        // charged as an essential cost for life. The reader must see both the pounds and the rule.
        $computed = $this->computed(
            ['salePrice' => '400000', 'buyPrice' => '150000', 'movingCosts' => '3000'],
            currentRunningCosts: '8000',
        );

        $this->assertCount(1, $computed);
        $this->assertStringContainsString(Money::fromPounds(3_000)->format(), $computed[0], 'the pounds actually charged');
        $this->assertStringContainsString(Money::fromPounds(8_000)->format(), $computed[0], 'the figure it was scaled from');
        $this->assertStringContainsString(Money::fromPounds(150_000)->format(), $computed[0], 'and the two prices that scaled it');
        $this->assertStringContainsString(Money::fromPounds(400_000)->format(), $computed[0]);
    }

    public function test_nothing_is_reported_as_computed_when_the_reader_entered_the_figure(): void
    {
        // No noise: an entered running cost is the reader's own number and nothing was worked out.
        $this->assertSame([], $this->computed(
            ['salePrice' => '400000', 'buyPrice' => '150000', 'buyRunningCosts' => '4000'],
            currentRunningCosts: '8000',
        ));
    }

    public function test_the_one_percent_fallback_is_not_reported_as_computed(): void
    {
        // The other branch is an ASSUMED figure with its own disclosure, so it must not be claimed
        // twice under a heading that says the model worked it out from what the reader told it.
        $this->assertSame([], $this->computed(['salePrice' => '400000', 'buyPrice' => '150000', 'movingCosts' => '3000']));
    }

    public function test_a_plan_that_never_buys_is_told_nothing(): void
    {
        // The scaled figure only reaches a projection that buys, so asserting it elsewhere would
        // make the reader plan around a cost the model never charges them.
        $this->assertSame([], $this->computed(
            ['salePrice' => '400000', 'annualRent' => '18000'],
            currentRunningCosts: '8000',
        ));
    }
}
