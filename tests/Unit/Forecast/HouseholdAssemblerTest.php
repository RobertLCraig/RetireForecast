<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\CouncilTaxBand;
use RetireForecast\FinanceEngine\Dto\DbPension;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\PensionEscalationBasis;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\RelationshipStatus;
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

    public function test_relationship_status_defaults_to_married_when_absent_and_maps_when_set(): void
    {
        $state = BuilderStateFixture::full();

        // Absent key → married (so an existing scenario keeps today's spousal treatment).
        unset($state['relationshipStatus']);
        $this->assertSame(
            RelationshipStatus::MarriedOrCivilPartnership,
            (new HouseholdAssembler)->household($state)->relationshipStatus,
        );

        // Explicit cohabiting flows through.
        $state['relationshipStatus'] = 'cohabiting';
        $this->assertSame(
            RelationshipStatus::Cohabiting,
            (new HouseholdAssembler)->household($state)->relationshipStatus,
        );
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

    public function test_a_dc_annuity_purchase_maps_onto_the_pension(): void
    {
        $dc = fn (array $annuity): DcPension => (new HouseholdAssembler)->household([
            'householdName' => 'Annuity', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1958-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expenseLines' => [['id' => 'e1', 'amount' => '10000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'pensions' => [array_merge(
                ['id' => 'pn1', 'subtype' => 'dc', 'ownerId' => 'p1', 'currentValue' => '200000', 'earliestAccessAge' => '57'],
                $annuity,
            )],
        ])->pensions[0];

        // No toggle, or toggle off, → no annuity even if the figures are present.
        $this->assertNull($dc([])->annuityPurchase);
        $this->assertNull($dc(['annuitise' => false, 'annuityAmount' => '100000', 'annuityAtAge' => '65'])->annuityPurchase);

        // Toggled on → a joint RPI annuity with the entered figures.
        $joint = $dc([
            'annuitise' => true, 'annuityAmount' => '100000', 'annuityAtAge' => '65',
            'annuityRate' => '7.2', 'annuityEscalation' => 'rpi', 'annuityJoint' => true, 'annuitySurvivorFraction' => '50',
        ])->annuityPurchase;
        $this->assertNotNull($joint);
        $this->assertSame(65, $joint->atAge);
        $this->assertSame(10_000_000, $joint->amount->pence);
        $this->assertSame(720, $joint->rate->basisPoints);
        $this->assertSame(PensionEscalationBasis::Rpi, $joint->escalation);
        $this->assertSame(5000, $joint->survivorFraction->basisPoints);

        // Single life → null survivor fraction; a blank rate defaults to the sourced ~7.2%.
        $single = $dc([
            'annuitise' => true, 'annuityAmount' => '50000', 'annuityAtAge' => '60', 'annuityRate' => '', 'annuityJoint' => false,
        ])->annuityPurchase;
        $this->assertNull($single->survivorFraction);
        $this->assertSame(PensionEscalationBasis::None, $single->escalation);
        $this->assertSame(720, $single->rate->basisPoints);

        // Toggle on but the amount left blank → nothing built (no half-specified annuity).
        $this->assertNull($dc(['annuitise' => true, 'annuityAtAge' => '65'])->annuityPurchase);
    }

    /**
     * Completeness for the Defined Benefit escalation controls (board card 0035). Every basis the
     * select offers must reach the DTO, and the fixed rate with it: the whole defect was a field
     * that was stored, validated, rendered and mapped, and then read by nothing. A basis that
     * cannot even be assembled is dead one step earlier.
     */
    public function test_every_db_escalation_basis_and_its_fixed_rate_reach_the_dto(): void
    {
        $db = fn (array $fields) => (new HouseholdAssembler)->household([
            'householdName' => 'Escalation', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1960-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expenseLines' => [['id' => 'e1', 'amount' => '20000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'pensions' => [array_merge(
                ['id' => 'pn1', 'subtype' => 'db', 'ownerId' => 'p1', 'accruedAnnualPension' => '9000', 'normalRetirementAge' => '65'],
                $fields,
            )],
        ])->pensions[0];

        foreach (PensionEscalationBasis::cases() as $basis) {
            $assembled = $db(['revaluationBasis' => $basis->value, 'escalationInPayment' => $basis->value]);
            $this->assertSame($basis, $assembled->revaluationBasis);
            $this->assertSame($basis, $assembled->escalationInPayment);
        }

        // The reader's fixed rate arrives as entered; blank falls back to the disclosed default.
        $this->assertSame(500, $db(['fixedEscalationRate' => '5'])->fixedEscalationRate()->basisPoints);
        $this->assertNull($db(['fixedEscalationRate' => ''])->fixedEscalationRate);
        $this->assertSame(
            DbPension::DEFAULT_FIXED_ESCALATION_BPS,
            $db(['fixedEscalationRate' => ''])->fixedEscalationRate()->basisPoints,
        );
    }

    public function test_an_income_amount_is_annualised_by_its_pay_frequency(): void
    {
        $household = (new HouseholdAssembler)->household([
            'householdName' => 'Freq', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1958-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expenseLines' => [['id' => 'e1', 'amount' => '10000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'incomeStreams' => [
                // DLA at the four-weekly DWP cadence: £600.00 every 4 weeks = £7,800.00 a year.
                ['id' => 'i1', 'ownerId' => 'p1', 'type' => 'other', 'grossAnnual' => '600.00', 'frequency' => 'four_weekly', 'taxable' => false, 'inflationLinked' => true, 'startAge' => '0'],
                // Rent quoted monthly: £1,500/mo = £18,000 a year.
                ['id' => 'i2', 'ownerId' => 'p1', 'type' => 'rental', 'grossAnnual' => '1500', 'frequency' => 'monthly', 'taxable' => true, 'inflationLinked' => true, 'startAge' => '0'],
                // No frequency = annual (back-compat): the figure is used as-is.
                ['id' => 'i3', 'ownerId' => 'p1', 'type' => 'annuity', 'grossAnnual' => '5000', 'taxable' => true, 'inflationLinked' => false, 'startAge' => '0'],
            ],
        ]);

        $this->assertSame(780_000, $household->incomeStreams[0]->grossAnnual->pence);   // £600.00 × 13
        $this->assertSame(1_800_000, $household->incomeStreams[1]->grossAnnual->pence); // £1,500 × 12
        $this->assertSame(500_000, $household->incomeStreams[2]->grossAnnual->pence);   // £5,000 × 1
    }

    public function test_capital_receipts_map_to_the_engine_dto(): void
    {
        // A documented one-off receipt: owner, calendar year, amount (today's money) and the
        // label that says where the money comes from — the no-magic-money input.
        $household = (new HouseholdAssembler)->household([
            'householdName' => 'Gift', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1958-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expenseLines' => [['id' => 'e1', 'amount' => '10000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'capitalReceipts' => [
                ['id' => 'cr1', 'ownerId' => 'p1', 'year' => '2029', 'amount' => '90000', 'label' => 'Family gift'],
                ['id' => 'cr2', 'ownerId' => 'p1', 'year' => '2031', 'amount' => '5000.50'], // label optional
            ],
        ]);

        $this->assertCount(2, $household->capitalReceipts);
        $this->assertSame('p1', $household->capitalReceipts[0]->ownerId);
        $this->assertSame(2029, $household->capitalReceipts[0]->calendarYear);
        $this->assertSame(90_000_00, $household->capitalReceipts[0]->amount->pence);
        $this->assertSame('Family gift', $household->capitalReceipts[0]->label);
        $this->assertSame('', $household->capitalReceipts[1]->label);
        $this->assertSame(5_000_50, $household->capitalReceipts[1]->amount->pence);
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
        $this->assertSame(780_000, $stream->grossAnnual->pence); // £600.00 × 13 — still annualised
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

    /**
     * @param  array<string, mixed>  $person
     */
    private function personFrom(array $person): Person
    {
        return (new HouseholdAssembler)->household([
            'householdName' => 'Cover', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1966-01-01', 'sex' => 'male', 'employmentStatus' => 'employed', 'grossSalary' => '40000'] + $person],
            'expenseLines' => [['id' => 'e1', 'amount' => '10000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
        ])->persons[0];
    }

    public function test_death_in_service_cover_reaches_the_person_stated_either_way(): void
    {
        // A multiple is held as a Percent (400% = 4x), so it stays exact under the no-floats rule
        // and sizes itself on whatever the salary is in the year of death.
        $multiple = $this->personFrom(['deathInServiceMode' => 'multiple', 'deathInServiceMultiple' => '4'])->deathInServiceCover;
        $this->assertNotNull($multiple);
        $this->assertSame(
            Money::fromPounds(160_000)->pence,
            $multiple->amountAt(Money::fromPounds(40_000))->pence,
        );
        $this->assertSame('4x salary', $multiple->describe());

        $fixed = $this->personFrom(['deathInServiceMode' => 'fixed', 'deathInServiceSum' => '150000'])->deathInServiceCover;
        $this->assertNotNull($fixed);
        $this->assertSame(
            Money::fromPounds(150_000)->pence,
            $fixed->amountAt(Money::fromPounds(40_000))->pence,
            'a fixed sum assured ignores the salary, by definition',
        );
    }

    public function test_no_cover_is_the_default_and_a_half_filled_input_never_claims_a_policy(): void
    {
        // Absent, blank, and "a mode chosen but no figure typed" must all mean NO cover — never a
        // zero-value policy the reader would see listed as if it existed.
        $this->assertNull($this->personFrom([])->deathInServiceCover);
        $this->assertNull($this->personFrom(['deathInServiceMode' => '', 'deathInServiceMultiple' => ''])->deathInServiceCover);
        $this->assertNull($this->personFrom(['deathInServiceMode' => 'multiple', 'deathInServiceMultiple' => ''])->deathInServiceCover);
        $this->assertNull($this->personFrom(['deathInServiceMode' => 'fixed', 'deathInServiceSum' => ''])->deathInServiceCover);
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

    public function test_property_costs_growth_reaches_the_profile_and_defaults_to_none(): void
    {
        // Completeness: the above-CPI growth entered in the builder demonstrably reaches the
        // engine profile; an absent key (every scenario saved before the field) means none.
        $state = [
            'householdName' => 'X',
            'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1960-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expenseLines' => [
                ['id' => 'a', 'label' => 'Service Charge', 'amount' => '6000', 'category' => 'essential', 'savedAsAsset' => false],
            ],
            'expense' => ['survivorFactor' => '70', 'propertyCostsGrowthPct' => '1.5'],
        ];

        $profile = (new HouseholdAssembler)->household($state)->expenseProfile;
        $this->assertSame(150, $profile->propertyCostsRealGrowth?->basisPoints);
        $this->assertSame(Money::fromPounds(6_000)->pence, $profile->propertyCosts()->pence, 'the bucket the growth applies to');

        unset($state['expense']['propertyCostsGrowthPct']);
        $this->assertNull((new HouseholdAssembler)->household($state)->expenseProfile->propertyCostsRealGrowth);
    }

    /**
     * @return array<string, mixed> builder state for a home carrying the ESIS quote's mortgage
     */
    private function repaymentMortgageState(array $propertyOverrides = []): array
    {
        return [
            'householdName' => 'X',
            'region' => 'england_wales_ni',
            'baseTaxYear' => '2026-27',
            'people' => [['id' => 'p1', 'dob' => '1960-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expenseLines' => [
                ['id' => 'a', 'label' => 'Food', 'amount' => '6000', 'category' => 'essential', 'savedAsAsset' => false],
            ],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => true,
            'property' => array_merge([
                'currentValue' => '400000',
                'ownership' => 'mortgaged',
                'outstandingMortgage' => '160000',
                'mortgageRepaymentTermMonths' => '192',
                'mortgageRepaymentStartYear' => '2026',
                'mortgageRepaymentStartMonth' => '9',
                'mortgageRepaymentRate' => '6.23',
                'mortgageRepaymentInitialMonths' => '60',
                'mortgageRepaymentRevertRate' => '7.24',
            ], $propertyOverrides),
        ];
    }

    public function test_repayment_mortgage_terms_reach_the_engine_property(): void
    {
        // Completeness: every field of a lender's illustration entered in the builder must reach
        // the engine, or the amortisation silently models a different loan from the quoted one.
        $property = (new HouseholdAssembler)->household($this->repaymentMortgageState())->primaryResidence;

        $terms = $property?->repaymentTerms;
        $this->assertNotNull($terms);
        $this->assertSame(192, $terms->termMonths);
        $this->assertSame(2026, $terms->firstPaymentYear);
        $this->assertSame(9, $terms->firstPaymentMonth);
        $this->assertSame(2042, $terms->finalPaymentYear());

        $this->assertCount(2, $terms->ratePeriods, 'a deal rate then a reversion rate');
        $this->assertSame(623, $terms->ratePeriods[0]->annualRate->basisPoints);
        $this->assertSame(60, $terms->ratePeriods[0]->months);
        $this->assertSame(724, $terms->ratePeriods[1]->annualRate->basisPoints);
        $this->assertNull($terms->ratePeriods[1]->months, 'the reversion runs to the end of the term');

        // The loan amount has ONE home — the outstanding mortgage, never restated in the terms.
        $this->assertSame(16_000_000, $property->outstandingMortgage?->pence);
    }

    public function test_no_repayment_term_leaves_the_mortgage_on_its_pre_existing_shape(): void
    {
        // Every scenario saved before these fields existed has no term: the balance must stay
        // static (interest-only / RIO), exactly as before.
        $state = $this->repaymentMortgageState(['mortgageRepaymentTermMonths' => '']);
        $this->assertNull((new HouseholdAssembler)->household($state)->primaryResidence?->repaymentTerms);

        unset($state['property']['mortgageRepaymentTermMonths']);
        $this->assertNull((new HouseholdAssembler)->household($state)->primaryResidence?->repaymentTerms);
    }

    public function test_one_rate_for_the_whole_term_makes_a_single_rate_period(): void
    {
        // No deal length / no reversion rate = the entered rate runs throughout.
        $state = $this->repaymentMortgageState([
            'mortgageRepaymentInitialMonths' => '',
            'mortgageRepaymentRevertRate' => '',
        ]);

        $terms = (new HouseholdAssembler)->household($state)->primaryResidence?->repaymentTerms;
        $this->assertCount(1, $terms->ratePeriods);
        $this->assertSame(623, $terms->ratePeriods[0]->annualRate->basisPoints);
        $this->assertNull($terms->ratePeriods[0]->months);
    }

    public function test_a_missing_first_payment_date_falls_back_to_the_base_year(): void
    {
        $state = $this->repaymentMortgageState([
            'mortgageRepaymentStartYear' => '',
            'mortgageRepaymentStartMonth' => '',
        ]);

        $terms = (new HouseholdAssembler)->household($state)->primaryResidence?->repaymentTerms;
        $this->assertSame(2026, $terms->firstPaymentYear, 'the scenario base year');
        $this->assertSame(1, $terms->firstPaymentMonth);
    }

    public function test_the_bought_homes_own_costs_and_growth_reach_the_housing_action(): void
    {
        // Completeness: a park home's pitch fee and its NEGATIVE growth must reach the engine, or
        // the option is silently modelled as an ordinary appreciating freehold with 1% upkeep.
        $state = BuilderStateFixture::full();
        $state['housing']['buyRunningCosts'] = '3000';
        $state['housing']['buyGrowthReal'] = '-8';

        $action = (new HouseholdAssembler)->housingAction($state['housing']);

        $this->assertSame(300_000, $action->buyRunningCosts?->pence);
        $this->assertSame(-800, $action->buyGrowthOverride?->basisPoints, 'a negative rate must survive the mapping');
    }

    public function test_absent_bought_home_costs_and_growth_leave_the_engine_defaults(): void
    {
        $state = BuilderStateFixture::full();
        unset($state['housing']['buyRunningCosts'], $state['housing']['buyGrowthReal']);

        $action = (new HouseholdAssembler)->housingAction($state['housing']);

        $this->assertNull($action->buyRunningCosts);
        $this->assertNull($action->buyGrowthOverride);
    }

    public function test_the_letting_cost_rates_a_reader_enters_reach_the_property(): void
    {
        // Card 0030 requires each letting cost to be EDITABLE, which means the form key has to
        // reach the DTO the engine reads. An explicit 0 must survive as a real zero (the reader
        // manages the let themselves), not be lost and quietly replaced by the 12% default.
        $state = BuilderStateFixture::full();
        $state['property']['isLet'] = true;
        $state['property']['lettingManagementRate'] = '0';
        $state['property']['lettingVoidRate'] = '4.5';

        $property = (new HouseholdAssembler)->household($state)->primaryResidence;

        $this->assertSame(0, $property?->lettingManagementRate()->basisPoints);
        $this->assertSame(450, $property?->lettingVoidRate()->basisPoints);
        $this->assertSame(
            Property::DEFAULT_LETTING_MAINTENANCE_BPS,
            $property?->lettingMaintenanceRate()->basisPoints,
            'the rate left blank still takes the disclosed default',
        );
    }

    public function test_the_council_tax_bill_and_a_disabled_band_reduction_reach_the_property(): void
    {
        // Card 0047. Both halves have to reach the DTO or the engine charges the bill in full for
        // life: the bill itself, held apart from the running costs so the discounts can act on it,
        // and the band, which is what says the disabled band reduction applies and from where.
        $state = BuilderStateFixture::full();
        $state['property']['councilTax'] = '2100';
        $state['property']['councilTaxDisabledBand'] = 'e';

        $property = (new HouseholdAssembler)->household($state)->primaryResidence;

        $this->assertSame(2_100_00, $property?->annualCouncilTax?->pence);
        $this->assertSame(CouncilTaxBand::E, $property?->disabledBandReduction);

        // Neither is entered on the ordinary home: the bill stays inside the running costs, and no
        // band means no reduction is being claimed. That is what every scenario stored before this
        // decodes to, so adding the inputs moves no existing figure.
        $blank = BuilderStateFixture::full();
        $blank['property']['councilTax'] = '';
        $blank['property']['councilTaxDisabledBand'] = '';

        $plain = (new HouseholdAssembler)->household($blank)->primaryResidence;
        $this->assertNull($plain?->annualCouncilTax);
        $this->assertNull($plain?->disabledBandReduction);
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

    public function test_an_allowed_absence_bracketed_by_occupation_reaches_the_engine(): void
    {
        // Lived in 2006-2010, away on a UK posting 2010-2016, back home 2016-2020, let after.
        // The absence is bracketed by real occupation, so its 72 months are handed over raw
        // (the engine, not this mapper, applies the 4-year cap).
        $history = (new HouseholdAssembler)->cgtHistoryFrom([
            'everLet' => true,
            'cgtHistory' => [
                'purchasePrice' => '150000', 'acquisitionYear' => '2006',
                'periods' => [
                    ['fromYear' => '2006', 'use' => 'main_home'],
                    ['fromYear' => '2010', 'use' => 'absence_uk_work'],
                    ['fromYear' => '2016', 'use' => 'main_home'],
                    ['fromYear' => '2020', 'use' => 'let'],
                ],
            ],
        ], 2026);

        $this->assertNotNull($history);
        $this->assertSame((4 + 4) * 12, $history->mainResidenceMonths);
        $this->assertSame(6 * 12, $history->absenceWorkElsewhereUkMonths);
        $this->assertSame(0, $history->absenceAnyReasonMonths);
        $this->assertSame(0, $history->absenceWorkAbroadMonths);
    }

    public function test_an_absence_never_returned_from_earns_nothing_unless_it_was_for_work(): void
    {
        // Same timeline both times: lived in, then away to the sale with no return. The
        // any-reason allowance needs the owner to come back, so it earns nothing; a job that
        // kept them away is excused that test, so its months still count.
        $timeline = static fn (string $use): array => [
            'everLet' => true,
            'cgtHistory' => [
                'purchasePrice' => '150000', 'acquisitionYear' => '2006',
                'periods' => [
                    ['fromYear' => '2006', 'use' => 'main_home'],
                    ['fromYear' => '2016', 'use' => $use],
                ],
            ],
        ];

        $neverReturned = (new HouseholdAssembler)->cgtHistoryFrom($timeline('absence_any'), 2026);
        $this->assertSame(0, $neverReturned?->absenceAnyReasonMonths);

        $keptAwayByWork = (new HouseholdAssembler)->cgtHistoryFrom($timeline('absence_abroad'), 2026);
        $this->assertSame(10 * 12, $keptAwayByWork?->absenceWorkAbroadMonths);
    }

    public function test_an_absence_before_ever_living_there_earns_nothing(): void
    {
        // Away first, moved in later: there is no occupation before the absence, so it cannot
        // be deemed occupation however good the reason.
        $history = (new HouseholdAssembler)->cgtHistoryFrom([
            'everLet' => true,
            'cgtHistory' => [
                'purchasePrice' => '150000', 'acquisitionYear' => '2006',
                'periods' => [
                    ['fromYear' => '2006', 'use' => 'absence_abroad'],
                    ['fromYear' => '2012', 'use' => 'main_home'],
                ],
            ],
        ], 2026);

        $this->assertSame(0, $history?->absenceWorkAbroadMonths);
        $this->assertSame(14 * 12, $history?->mainResidenceMonths);
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
