<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Forecast\HouseholdAssembler;
use App\Forecast\ResultPresenter;
use App\Livewire\ScenarioResults;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * Board card 0052: the tool reported unmet spend as one flat number and moved on. When the money
 * that goes unpaid is a mortgage instalment the real-world event is possession, not a smaller
 * weekly shop, and an unpaid council tax bill carries a liability order and deductions from
 * benefits — neither of which reads any differently here from a missed grocery bill. This proves
 * the framing (secured vs not, and the priority-debt naming) and the route to free help.
 */
class PriorityDebtSignpostingTest extends TestCase
{
    use RefreshDatabase;

    /** A retired household whose income cannot meet its spending: the plan fails from year one. */
    private function shortState(bool $mortgaged): array
    {
        $state = [
            'name' => 'Runs short', 'householdName' => 'Runs short', 'region' => 'england_wales_ni',
            'baseTaxYear' => '2026-27', 'variant' => 'stay_put',
            'people' => [['id' => 'p1', 'name' => 'Pat', 'dob' => '1950-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'pensions' => [['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '150']],
            'expenseLines' => [['id' => 'e1', 'label' => 'Essentials', 'amount' => '30000', 'category' => 'essential', 'savedAsAsset' => false]],
            'expense' => ['essential' => '', 'discretionary' => '', 'survivorFactor' => '70'],
            'hasProperty' => false,
            'housing' => ['salePrice' => '0', 'buyPrice' => '', 'annualRent' => '', 'rentInflationReal' => '', 'movingCosts' => '', 'sellingCosts' => []],
        ];

        if ($mortgaged) {
            $state['hasProperty'] = true;
            $state['property'] = [
                'currentValue' => '300000', 'ownership' => 'mortgaged', 'outstandingMortgage' => '90000',
            ];
            $state['expenseLines'][] = ['id' => 'e2', 'label' => 'Mortgage', 'amount' => '6000', 'category' => 'essential', 'savedAsAsset' => false];
        }

        return $state;
    }

    private function forecast(array $state): ForecastResult
    {
        $assembler = new HouseholdAssembler;

        return (new DeterministicForecaster(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi),
            new CohortLifeTable,
        ))->forecast(
            $assembler->household($state),
            AssumptionSetLibrary::default(),
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
        );
    }

    public function test_a_shortfall_on_a_live_mortgage_is_named_a_secured_debt_shortfall(): void
    {
        $panel = ResultPresenter::ladder($this->forecast($this->shortState(mortgaged: true)))['priorityDebt'];

        $this->assertNotNull($panel, 'a plan that cannot meet its spending gets the panel');
        $this->assertTrue($panel['secured'], 'the shortfall year still owes a mortgage on the home');
        $this->assertStringContainsString('secured', $panel['headline']);
        $this->assertStringContainsString('possession', $panel['headline']);
    }

    public function test_a_shortfall_with_no_mortgage_is_not_called_a_secured_debt_shortfall(): void
    {
        // A renter or an outright owner has no home at stake for the same gap, and telling them
        // their home could be taken would be an invented consequence.
        $panel = ResultPresenter::ladder($this->forecast($this->shortState(mortgaged: false)))['priorityDebt'];

        $this->assertNotNull($panel);
        $this->assertFalse($panel['secured']);
        $this->assertStringNotContainsString('possession', $panel['headline']);
    }

    public function test_a_shortfall_names_mortgage_and_council_tax_as_priority_debts(): void
    {
        $panel = ResultPresenter::ladder($this->forecast($this->shortState(mortgaged: false)))['priorityDebt'];

        $points = strtolower(implode(' ', $panel['points']));
        $this->assertStringContainsString('priority debt', $points);
        $this->assertStringContainsString('council tax', $points);
        $this->assertStringContainsString('mortgage', $points);
        $this->assertStringContainsString('liability order', $points);
    }

    public function test_a_plan_that_meets_its_spending_gets_no_priority_debt_panel(): void
    {
        // A panel that fires on every plan is noise, and noise is how a real warning stops being
        // read: a household whose income covers its spending has no shortfall to frame.
        $comfortable = $this->shortState(mortgaged: false);
        $comfortable['expenseLines'][0]['amount'] = '5000';
        $comfortable['accounts'] = [['id' => 'a1', 'ownerId' => 'p1', 'type' => 'cash', 'balance' => '250000']];

        $this->assertNull(ResultPresenter::ladder($this->forecast($comfortable))['priorityDebt']);
    }

    public function test_the_benefits_and_debt_contacts_column_is_off_until_it_is_asked_for(): void
    {
        $off = $this->blade('<x-sources-and-contacts :show-mortgage="false" :show-cgt="false" />');
        $off->assertDontSee('Benefits &amp; debt', false);

        $on = $this->blade('<x-sources-and-contacts :show-mortgage="false" :show-cgt="false" :show-benefits-debt="true" />');
        $on->assertSee('Benefits &amp; debt', false);
        $on->assertSee('National Debtline', false);
        $on->assertSee('Citizens Advice', false);
    }

    public function test_the_results_page_frames_a_secured_shortfall_and_offers_the_debt_contacts(): void
    {
        // The wiring half: a presenter array nothing renders helps nobody, and a Blade directive
        // can fail to compile silently. One rendered page, both halves of the card.
        $scenario = ScenarioFixture::fromState(User::factory()->create(), $this->shortState(mortgaged: true));

        Livewire::actingAs($scenario->user)
            ->test(ScenarioResults::class, ['scenario' => $scenario])
            ->assertSee('secured on your home')
            ->assertSee('priority debts')
            ->assertSee('Benefits &amp; debt', false)
            ->assertSee('National Debtline');
    }
}
