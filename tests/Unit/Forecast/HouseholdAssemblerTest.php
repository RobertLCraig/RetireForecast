<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;
use Tests\Support\BuilderStateFixture;
use Tests\Support\HouseholdFixture;

/**
 * The builder is the third consumer of the one canonical shape. This proves the
 * assembly is lossless: form-shaped strings covering every nested DTO and optional
 * field rebuild exactly the rich {@see HouseholdFixture} household and housing
 * action, pounds parsed to exact pence with no float drift.
 */
class HouseholdAssemblerTest extends TestCase
{
    public function test_it_rebuilds_the_full_household_dto_from_form_state(): void
    {
        $assembled = (new HouseholdAssembler)->assemble(BuilderStateFixture::full());

        $this->assertEquals(HouseholdFixture::household(), $assembled['household']);
        $this->assertEquals(HouseholdFixture::housingAction(), $assembled['housingAction']);
    }

    public function test_selling_costs_assemble_each_component_on_its_own_basis(): void
    {
        $action = (new HouseholdAssembler)->housingAction([
            'salePrice' => '400000',
            'sellingCosts' => [
                'estate_agent' => ['label' => 'Estate agent', 'basis' => 'percent', 'value' => '1.25'],
                'legal' => ['label' => 'Legal / conveyancing', 'basis' => 'fixed', 'value' => '1500'],
                'blank' => ['label' => 'Unused', 'basis' => 'fixed', 'value' => ''], // blank line costs nothing → dropped
            ],
        ]);

        $this->assertCount(2, $action->sellingCosts);
        $this->assertInstanceOf(Percent::class, $action->sellingCosts[0]->value);
        $this->assertSame(125, $action->sellingCosts[0]->value->basisPoints);
        $this->assertInstanceOf(Money::class, $action->sellingCosts[1]->value);
        $this->assertSame(150_000, $action->sellingCosts[1]->value->pence);
    }

    public function test_an_income_amount_is_annualised_by_its_pay_frequency(): void
    {
        $household = (new HouseholdAssembler)->household([
            'householdName' => 'Freq', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1958-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expenseLines' => [['id' => 'e1', 'amount' => '10000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'incomeStreams' => [
                // DLA at the real DWP cadence: £600.00 every 4 weeks = £9,747.40 a year.
                ['id' => 'i1', 'ownerId' => 'p1', 'type' => 'other', 'grossAnnual' => '600.00', 'frequency' => 'four_weekly', 'taxable' => false, 'inflationLinked' => true, 'startAge' => '0'],
                // Rent quoted monthly: £1,650/mo = £19,800 a year.
                ['id' => 'i2', 'ownerId' => 'p1', 'type' => 'rental', 'grossAnnual' => '1650', 'frequency' => 'monthly', 'taxable' => true, 'inflationLinked' => true, 'startAge' => '0'],
                // No frequency = annual (back-compat): the figure is used as-is.
                ['id' => 'i3', 'ownerId' => 'p1', 'type' => 'annuity', 'grossAnnual' => '5000', 'taxable' => true, 'inflationLinked' => false, 'startAge' => '0'],
            ],
        ]);

        $this->assertSame(974_740, $household->incomeStreams[0]->grossAnnual->pence);   // £600.00 × 13
        $this->assertSame(1_980_000, $household->incomeStreams[1]->grossAnnual->pence); // £1,650 × 12
        $this->assertSame(500_000, $household->incomeStreams[2]->grossAnnual->pence);   // £5,000 × 1
    }

    public function test_a_disability_benefit_income_is_forced_tax_free_whatever_the_flag_says(): void
    {
        // The type is the single source of truth for a tax-free benefit: DLA / AA / PIP are
        // disregarded from income tax AND the Pension Credit means test. So even a stale
        // taxable=true flag (e.g. copied from a rental row) must not tax it or dock benefit.
        $household = (new HouseholdAssembler)->household([
            'householdName' => 'DLA', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1958-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expenseLines' => [['id' => 'e1', 'amount' => '10000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'incomeStreams' => [
                ['id' => 'i1', 'ownerId' => 'p1', 'type' => 'disability_benefit', 'grossAnnual' => '600.00', 'frequency' => 'four_weekly', 'taxable' => true, 'inflationLinked' => true, 'startAge' => '0'],
            ],
        ]);

        $stream = $household->incomeStreams[0];
        $this->assertSame(IncomeStreamType::DisabilityBenefit, $stream->type);
        $this->assertFalse($stream->taxable); // the type overrides the taxable=true flag
        $this->assertTrue($stream->type->isTaxFreeBenefit());
        $this->assertSame(974_740, $stream->grossAnnual->pence); // £600.00 × 13 — still annualised
    }

    public function test_the_disability_benefit_flag_is_carried_through_to_the_person(): void
    {
        $household = (new HouseholdAssembler)->household([
            'householdName' => 'Disab', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'dob' => '1958-01-01', 'sex' => 'male', 'employmentStatus' => 'retired', 'receivesDisabilityBenefit' => true],
                ['id' => 'p2', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired'],
            ],
            'expenseLines' => [['id' => 'e1', 'amount' => '10000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
        ]);

        $this->assertTrue($household->persons[0]->receivesDisabilityBenefit);
        $this->assertFalse($household->persons[1]->receivesDisabilityBenefit); // absent flag = false
    }

    public function test_an_old_single_selling_cost_rate_maps_to_one_estate_agent_component(): void
    {
        // Back-compat: a scenario saved before the breakdown carried only the single rate.
        $action = (new HouseholdAssembler)->housingAction(['salePrice' => '400000', 'sellingCostRate' => '1.5']);

        $this->assertCount(1, $action->sellingCosts);
        $this->assertSame('Estate agent', $action->sellingCosts[0]->label);
        $this->assertSame(150, $action->sellingCosts[0]->value->basisPoints);
    }

    public function test_no_selling_cost_input_leaves_the_engine_default_to_apply(): void
    {
        // Absent or all-blank → null, so the engine applies its own default (the old behaviour).
        $this->assertNull((new HouseholdAssembler)->housingAction(['salePrice' => '400000'])->sellingCosts);
        $this->assertNull((new HouseholdAssembler)->housingAction([
            'salePrice' => '400000',
            'sellingCosts' => ['estate_agent' => ['label' => 'Estate agent', 'basis' => 'percent', 'value' => '']],
        ])->sellingCosts);
    }

    public function test_an_excluded_spend_line_is_dropped_from_every_total(): void
    {
        // A line switched off (included === false) is kept in the form-state but must not reach
        // any forecast total — essential, discretionary or the contingent (property) cost subset.
        $household = (new HouseholdAssembler)->household([
            'householdName' => 'X',
            'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1960-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expenseLines' => [
                ['id' => 'a', 'label' => 'Food', 'amount' => '10000', 'category' => 'essential', 'savedAsAsset' => false, 'included' => true],
                ['id' => 'b', 'label' => 'Holidays', 'amount' => '6000', 'category' => 'discretionary', 'savedAsAsset' => false, 'included' => false],
                ['id' => 'c', 'label' => 'Mortgage', 'amount' => '12000', 'category' => 'essential', 'savedAsAsset' => false, 'included' => false],
            ],
        ]);

        // Only the included Food line counts; the excluded discretionary and the excluded
        // (auto-classified property) mortgage contribute nothing.
        $this->assertSame(Money::fromPounds(10_000)->pence, $household->expenseProfile->essentialAnnualSpend->pence);
        $this->assertSame(0, $household->expenseProfile->discretionaryAnnualSpend->pence);
        $this->assertNull($household->expenseProfile->propertyCosts);
    }

    public function test_a_line_with_no_included_flag_counts_as_included(): void
    {
        // Back-compat: a line saved before the toggle existed has no flag and must still count.
        $household = (new HouseholdAssembler)->household([
            'householdName' => 'X',
            'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1960-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expenseLines' => [
                ['id' => 'a', 'label' => 'Food', 'amount' => '10000', 'category' => 'essential', 'savedAsAsset' => false],
            ],
        ]);

        $this->assertSame(Money::fromPounds(10_000)->pence, $household->expenseProfile->essentialAnnualSpend->pence);
    }

    public function test_cgt_history_reduces_the_occupation_timeline_to_months(): void
    {
        // Lived in 2006–2014 (main home), then let to the 2026 sale; jointly owned.
        $history = (new HouseholdAssembler)->cgtHistoryFrom([
            'everLet' => true,
            'cgtHistory' => [
                'purchasePrice' => '150000', 'improvementCosts' => '5000', 'acquisitionYear' => '2006',
                'jointlyOwned' => true, 'higherRateOnSale' => false,
                'periods' => [
                    ['fromYear' => '2006', 'use' => 'main_home'],
                    ['fromYear' => '2014', 'use' => 'let'],
                ],
            ],
        ], 2026);

        $this->assertNotNull($history);
        $this->assertSame((2026 - 2006) * 12, $history->ownershipMonths);   // 240
        $this->assertSame((2014 - 2006) * 12, $history->mainResidenceMonths); // 96 main-home months
        $this->assertSame(Money::fromPounds(150_000)->pence, $history->purchasePrice->pence);
        $this->assertSame(Money::fromPounds(5_000)->pence, $history->improvementCosts->pence);
        $this->assertSame(2, $history->owners);
        $this->assertFalse($history->higherRateOnSale);
    }

    public function test_cgt_history_is_null_without_letting_or_a_purchase_price(): void
    {
        // Not let → full PRR, no CGT history.
        $this->assertNull((new HouseholdAssembler)->cgtHistoryFrom([
            'everLet' => false, 'cgtHistory' => ['purchasePrice' => '150000'],
        ], 2026));

        // Let, but no purchase price → no gain to compute.
        $this->assertNull((new HouseholdAssembler)->cgtHistoryFrom([
            'everLet' => true, 'cgtHistory' => ['purchasePrice' => ''],
        ], 2026));
    }

    public function test_pounds_and_pence_parse_to_exact_pence(): void
    {
        $household = (new HouseholdAssembler)->household([
            'householdName' => 'X',
            'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1960-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expense' => ['essential' => '28000.50', 'discretionary' => '', 'survivorFactor' => ''],
            'pensions' => [['ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230.25']],
        ]);

        $this->assertSame(2_800_050, $household->expenseProfile->essentialAnnualSpend->pence);
        $this->assertSame(23_025, $household->pensions[0]->weeklyForecast->pence);
    }

    public function test_expense_line_items_derive_the_essential_and_discretionary_totals(): void
    {
        // Essential = sum of essential lines; discretionary = discretionary lines plus
        // *spent* self-investment (consumption that does not build an asset).
        $household = (new HouseholdAssembler)->household([
            'householdName' => 'X',
            'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1960-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expenseLines' => [
                ['id' => 'l1', 'label' => 'Bills', 'amount' => '20000', 'category' => 'essential'],
                ['id' => 'l2', 'label' => 'Food', 'amount' => '5000', 'category' => 'essential'],
                ['id' => 'l3', 'label' => 'Holidays', 'amount' => '8000', 'category' => 'discretionary'],
                ['id' => 'l4', 'label' => 'A course', 'amount' => '2000', 'category' => 'self_investment', 'savedAsAsset' => false],
            ],
        ]);

        $this->assertSame(2_500_000, $household->expenseProfile->essentialAnnualSpend->pence);
        $this->assertSame(1_000_000, $household->expenseProfile->discretionaryAnnualSpend->pence);
        $this->assertSame([], $household->accounts); // nothing saved, so no synthetic account
    }

    public function test_saved_self_investment_becomes_a_contributing_account_not_spend(): void
    {
        // A *saved* self-investment line builds net worth: it is not counted as spend,
        // and appears once as a contributing (balance-zero) account — one home per pound.
        $household = (new HouseholdAssembler)->household([
            'householdName' => 'X',
            'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1960-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expenseLines' => [
                ['id' => 'l1', 'label' => 'Bills', 'amount' => '20000', 'category' => 'essential'],
                ['id' => 'l2', 'label' => 'Savings plan', 'amount' => '3000', 'category' => 'self_investment', 'savedAsAsset' => true],
            ],
        ]);

        $this->assertSame(2_000_000, $household->expenseProfile->essentialAnnualSpend->pence);
        $this->assertSame(0, $household->expenseProfile->discretionaryAnnualSpend->pence);

        $this->assertCount(1, $household->accounts);
        $saved = $household->accounts[0];
        $this->assertSame(0, $saved->balance->pence);
        $this->assertSame(300_000, $saved->ongoingContributions->pence);
    }

    public function test_the_lifespan_what_if_maps_to_the_engine_longevity_adjustment(): void
    {
        $assembler = new HouseholdAssembler;
        $person = fn (string $mode, string $value): array => [
            'householdName' => 'X', 'region' => 'england_wales_ni',
            'expenseLines' => [['id' => 'l1', 'label' => 'Bills', 'amount' => '20000', 'category' => 'essential']],
            'people' => [['id' => 'p1', 'dob' => '1960-01-01', 'sex' => 'male', 'employmentStatus' => 'retired',
                'longevityMode' => $mode, 'longevityValue' => $value]],
        ];

        // peer (or a blank value) leaves the cohort-table average in place.
        $this->assertNull($assembler->household($person('peer', ''))->persons[0]->longevity);
        $this->assertNull($assembler->household($person('fixed_age', ''))->persons[0]->longevity);
        // fixed age and ± year offset map to the matching adjustment.
        $this->assertEquals(LongevityAdjustment::fixedAge(82), $assembler->household($person('fixed_age', '82'))->persons[0]->longevity);
        $this->assertEquals(LongevityAdjustment::offsetYears(-5), $assembler->household($person('offset_years', '-5'))->persons[0]->longevity);
    }

    public function test_a_fixed_age_lifespan_what_if_reaches_the_forecast_and_shortens_it(): void
    {
        // Completeness: the form-level lifespan lever must actually move the result. A couple
        // (both 68 in 2026) assumed to die at 80 ends the forecast in 2038 — strictly earlier
        // than the same couple left on the cohort-table average.
        $state = fn (string $mode, string $value): array => [
            'householdName' => 'Lifespan', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'dob' => '1958-04-01', 'sex' => 'female', 'employmentStatus' => 'retired', 'longevityMode' => $mode, 'longevityValue' => $value],
                ['id' => 'p2', 'dob' => '1958-09-01', 'sex' => 'male', 'employmentStatus' => 'retired', 'longevityMode' => $mode, 'longevityValue' => $value],
            ],
            'pensions' => [
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230'],
                ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '230'],
            ],
            'expenseLines' => [['id' => 'e', 'label' => 'Essentials', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
        ];

        $assembler = new HouseholdAssembler;
        $forecaster = new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable);
        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
        $assumptions = AssumptionSetLibrary::default();

        $fixed = $forecaster->forecast($assembler->household($state('fixed_age', '80')), $assumptions, $settings);
        $peer = $forecaster->forecast($assembler->household($state('peer', '')), $assumptions, $settings);

        $this->assertSame(2038, $fixed->finalCalendarYear);
        $this->assertGreaterThan($fixed->finalCalendarYear, $peer->finalCalendarYear);
    }
}
