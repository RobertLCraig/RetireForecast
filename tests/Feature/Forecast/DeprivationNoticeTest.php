<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Forecast\HouseholdAssembler;
use App\Forecast\LumpSumTaxShock;
use App\Forecast\ResultPresenter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\MonteCarlo\CareImpact;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * Board card 0049: nothing in this app said that spending or giving away capital can cost the
 * entitlement the same app models — the notional capital rule for means-tested benefits, the
 * deliberate deprivation test for care charging. These are the SURFACES: the results notes (which
 * the PDF shares), the lump-sum tax-shock panel (the screen where somebody decides to take money
 * out of a pot), and the equity-release copy, which is where the gift-with-reservation trap
 * belongs because that is the plan a household in this position hears about from friends.
 */
class DeprivationNoticeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $state
     * @return list<array{kind: string, text: string}>
     */
    private function notes(array $state, ?string $variant = null): array
    {
        $assembler = new HouseholdAssembler;
        $household = $assembler->household($state);
        $forecast = (new DeterministicForecaster(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi),
            new CohortLifeTable,
        ))->forecast($household, AssumptionSetLibrary::default(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
        $action = $assembler->housingAction($state['housing'] ?? []);

        // A variant is passed the way every live caller passes it: through housingActionFor, which
        // hands the action on only to a plan that buys.
        return $variant === null
            ? ResultPresenter::inputNotes($household, $forecast, $action)
            : ResultPresenter::inputNotes($household, $forecast, ResultPresenter::housingActionFor($action, $variant), $variant);
    }

    /**
     * A household that owns its home outright, with the sale and buy prices the Compare page runs
     * every variant against.
     *
     * @param  list<array<string, mixed>>  $withdrawals
     * @return array<string, mixed>
     */
    private function sellingState(array $withdrawals = []): array
    {
        return [
            'householdName' => 'Sellers', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'name' => 'Pat', 'dob' => '1953-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'pensions' => [
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230'],
                ...($withdrawals === [] ? [] : [[
                    'id' => 'dc1', 'ownerId' => 'p1', 'subtype' => 'dc', 'currentValue' => '200000',
                    'earliestAccessAge' => '55', 'withdrawals' => $withdrawals,
                ]]),
            ],
            'expenseLines' => [['id' => 'e1', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => true,
            'property' => ['currentValue' => '350000', 'ownership' => 'outright'],
            'housing' => ['salePrice' => '350000', 'buyPrice' => '200000', 'annualRent' => '12000'],
        ];
    }

    /**
     * @param  list<array{kind: string, text: string}>  $notes
     * @return list<string>
     */
    private static function deprivationTexts(array $notes): array
    {
        return array_values(array_map(
            static fn (array $n): string => $n['text'],
            array_filter($notes, static fn (array $n): bool => $n['kind'] === 'capital_deprivation'),
        ));
    }

    /**
     * A retired couple whose plan hands a large lump to a family member as a one-off cost — the
     * shape a "help the children now" plan actually takes in this builder.
     *
     * @return array<string, mixed>
     */
    private function givingState(string $oneOffAmount = '60000'): array
    {
        return [
            'householdName' => 'Givers', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'name' => 'Pat', 'dob' => '1953-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'pensions' => [['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230']],
            'accounts' => [['id' => 'a1', 'ownerId' => 'p1', 'type' => 'cash', 'balance' => '200000']],
            'expenseLines' => [['id' => 'e1', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'oneOffCosts' => [['id' => 'o1', 'label' => 'Gift to our daughter', 'amount' => $oneOffAmount, 'atAge' => '75']],
        ];
    }

    public function test_a_plan_that_moves_a_large_sum_is_warned_about_deprivation_of_capital(): void
    {
        $notes = $this->notes($this->givingState());
        $kinds = array_column($notes, 'kind');
        $this->assertContains('capital_deprivation', $kinds, 'a £60,000 gift out raises the note');

        $text = $notes[array_search('capital_deprivation', $kinds, true)]['text'];
        $this->assertStringContainsString('notional capital', $text, 'the benefits rule is named');
        $this->assertStringContainsString('deliberate deprivation', $text, 'the care-charging rule is named');
        $this->assertStringContainsString('benefits check before you move the money', $text);
    }

    public function test_a_plan_that_moves_nothing_large_gets_no_deprivation_note(): void
    {
        // A disclosure that fires on every plan is noise, and noise is how a real warning stops
        // being read. The same household with a small one-off must be silent.
        $this->assertNotContains('capital_deprivation', array_column($this->notes($this->givingState('900')), 'kind'));
    }

    public function test_selling_the_home_at_year_zero_is_warned_about(): void
    {
        // The year-0 sell variants sell BEFORE the projection starts, so the engine never sees the
        // sale and cannot raise its own warning — the presenter has to, or the single largest
        // capital move this tool exists to compare goes unwarned. Driven through housingActionFor,
        // as every live caller is, so the sell-and-rent plan (which gets no action) is covered.
        foreach (['rent', 'buy_outright'] as $variant) {
            $texts = self::deprivationTexts($this->notes($this->sellingState(), $variant));
            $this->assertNotSame([], $texts, "the {$variant} plan sells the home and is warned");
            $this->assertStringContainsString('selling your home', $texts[0], $variant);
        }

        // Stay put sells nothing at year 0.
        $this->assertSame([], self::deprivationTexts($this->notes($this->sellingState(), 'stay_put')));
    }

    public function test_a_year_zero_sale_does_not_hide_a_later_large_move(): void
    {
        $texts = self::deprivationTexts($this->notes(
            $this->sellingState([['kind' => 'ufpls', 'amount' => '60000', 'atAge' => '78']]),
            'rent',
        ));

        $joined = implode(' ', $texts);
        $this->assertStringContainsString('selling your home', $joined, 'the sale is named');
        $this->assertStringContainsString('out of a pension', $joined, 'the later pension withdrawal is named too');
    }

    public function test_the_care_panel_carries_the_deliberate_deprivation_warning(): void
    {
        $panel = ResultPresenter::careImpactPanel(new CareImpact(0.25, Money::fromPounds(80_000), Money::fromPounds(250_000)));

        $this->assertNotNull($panel);
        $this->assertStringContainsString('deliberate deprivation', $panel['deprivation']);
        $this->assertStringContainsString('benefits check before you move the money', $panel['deprivation']);
    }

    public function test_the_equity_release_copy_warns_about_gift_with_reservation_of_benefit(): void
    {
        $notes = $this->notes([
            'householdName' => 'Roll-up', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'name' => 'Pat', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'pensions' => [['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230']],
            'expenseLines' => [['id' => 'e1', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => true,
            'property' => [
                'currentValue' => '350000', 'ownership' => 'mortgaged', 'outstandingMortgage' => '118000',
                'mortgageRollUpRate' => '6.5',
            ],
        ]);

        $flag = array_values(array_filter($notes, fn (array $n): bool => $n['kind'] === 'lifetime_mortgage_rollup'));
        $this->assertCount(1, $flag);
        $this->assertStringContainsString('gift with reservation of benefit', $flag[0]['text']);
        $this->assertStringContainsString('stays in your estate', $flag[0]['text']);
        $this->assertStringContainsString('benefits check before you move the money', $flag[0]['text']);
    }

    public function test_the_lump_sum_panel_warns_beside_the_withdrawal_it_prices(): void
    {
        // The card's own direction: this is the screen where somebody decides to take money out
        // of a pot, so it is where the warning has to be.
        $scenario = ScenarioFixture::fromState(User::factory()->create(), [
            'name' => 'Big draw', 'householdName' => 'Big draw', 'region' => 'england_wales_ni',
            'baseTaxYear' => '2026-27', 'variant' => 'stay', 'ihtModelled' => false,
            'people' => [['id' => 'p1', 'name' => '', 'dob' => '1965-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expense' => ['essential' => '18000', 'discretionary' => '6000', 'survivorFactor' => '70'],
            'pensions' => [[
                'ownerId' => 'p1', 'subtype' => 'dc', 'currentValue' => '400000', 'earliestAccessAge' => '55',
                'withdrawals' => [['kind' => 'ufpls', 'amount' => '60000', 'atAge' => '62']],
            ]],
            'hasProperty' => false,
            'housing' => ['salePrice' => '0'],
        ]);

        $shock = (new LumpSumTaxShock)->assess($scenario);

        $this->assertNotNull($shock);
        $joined = implode(' ', $shock['warnings']);
        $this->assertStringContainsString('notional capital', $joined);
        $this->assertStringContainsString('benefits check before you move the money', $joined);
    }
}
