<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use App\Forecast\ResultPresenter;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Care\CareAssumptions;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\DbPension;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\PortfolioAllocation;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\Iht\InheritanceTaxCalculator;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\StatePension\StatePensionUprating;
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
     * @param  list<array<string, mixed>>|null  $expenseLines
     * @return list<string> the assumed-figure disclosures a reader would see
     */
    private function disclosures(array $housing, string $currentRunningCosts = '', string $accountType = 'isa', ?array $expenseLines = null, ?string $propertyCostsGrowthPct = null, ?AssumptionSet $set = null, ?string $variant = null, array $property = [], ?ForecastSettings $settings = null): array
    {
        $state = [
            'householdName' => 'Movers', 'region' => 'england_wales_ni', 'baseTaxYear' => '2026-27',
            'people' => [['id' => 'p1', 'dob' => '1955-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'pensions' => [['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230']],
            'accounts' => [['id' => 'a1', 'ownerId' => 'p1', 'type' => $accountType, 'balance' => '150000']],
            'expenseLines' => $expenseLines ?? [['id' => 'e1', 'amount' => '18000', 'category' => 'essential']],
            'expense' => array_filter([
                'survivorFactor' => '70',
                'propertyCostsGrowthPct' => $propertyCostsGrowthPct,
            ], static fn (?string $v): bool => $v !== null),
            'hasProperty' => true,
            'property' => ['currentValue' => '400000', 'ownership' => 'outright', 'runningCosts' => $currentRunningCosts] + $property,
            'housing' => $housing,
        ];

        $assembler = new HouseholdAssembler;
        $household = $assembler->household($state);
        $run = $settings ?? new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
        $forecast = (new DeterministicForecaster(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi),
            new CohortLifeTable,
        ))->forecast($household, AssumptionSetLibrary::default(), $run);

        // The assumption set and the run settings are passed only where a test is about a figure
        // that lives in one, so every other case keeps asserting on the household's own defaults
        // and nothing else. The forecast runs on the SAME settings the notes are computed from,
        // so a disclosure can never describe a run that did not happen.
        $notes = ResultPresenter::inputNotes($household, $forecast, $assembler->housingAction($housing), $variant, $set, $settings);

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

    public function test_using_the_isa_allowance_is_disclosed_because_nobody_asked_for_it(): void
    {
        // The engine moves money out of a taxable account into an ISA each year on the household's
        // behalf. Nobody entered that: it is an ACTION the model takes, it changes the tax bill
        // and therefore the answer, and until it was disclosed a reader had no way to know it had
        // happened. The disclosure states the pounds the projection actually moved, so it cannot
        // drift from what was done.
        $disclosures = $this->disclosures(['salePrice' => '400000', 'annualRent' => '18000'], accountType: 'gia');

        $this->assertCount(1, $disclosures);
        $this->assertStringContainsString('ISA allowance', $disclosures[0]);
        $this->assertMatchesRegularExpression('/£[\d,]+\.\d\d in \d{4}/', $disclosures[0],
            'the disclosure must name what was actually moved, and when');
    }

    public function test_nothing_is_disclosed_about_isas_when_the_money_is_already_sheltered(): void
    {
        // No noise: a household holding nothing outside an ISA has nothing to move, so the note
        // must not appear. (The default fixture account is an ISA.)
        $this->assertSame([], $this->disclosures(['salePrice' => '400000', 'annualRent' => '18000']));
    }

    public function test_the_assumed_property_cost_growth_is_disclosed_with_its_value(): void
    {
        // Card 0028. A household paying a service charge and giving no growth rate used to have
        // that charge ride plain CPI: a figure nobody entered, that the evidence rules out, and
        // that compounds into thousands a year by the survivor's years. The engine now supplies a
        // rate for itself, so it must be on the screen with its value and its reason.
        $disclosures = $this->disclosures(
            ['salePrice' => '400000', 'annualRent' => '18000'],
            expenseLines: [
                ['id' => 'e1', 'amount' => '18000', 'category' => 'essential'],
                ['id' => 'e2', 'label' => 'Service charge', 'amount' => '3000', 'category' => 'essential'],
            ],
        );

        $this->assertCount(1, $disclosures);
        $rate = Percent::fromBasisPoints(ExpenseProfile::DEFAULT_PROPERTY_COSTS_REAL_GROWTH_BPS);
        $pct = rtrim(rtrim(number_format($rate->asPercent(), 2), '0'), '.');

        $this->assertStringContainsString("{$pct}% a year above inflation", $disclosures[0]);
        $this->assertStringContainsString(Money::fromPounds(3_000)->format(), $disclosures[0], 'the bucket it applies to');
    }

    public function test_nothing_is_assumed_about_property_costs_when_the_reader_gave_a_rate(): void
    {
        // No noise, and no overriding: an explicit rate (including an explicit zero) is the reader's
        // figure, not one the engine supplied, so it must not be reported as assumed.
        $this->assertSame([], $this->disclosures(
            ['salePrice' => '400000', 'annualRent' => '18000'],
            expenseLines: [
                ['id' => 'e1', 'amount' => '18000', 'category' => 'essential'],
                ['id' => 'e2', 'label' => 'Service charge', 'amount' => '3000', 'category' => 'essential'],
            ],
            propertyCostsGrowthPct: '0',
        ));
    }

    public function test_the_single_property_volatility_uplift_is_disclosed_with_its_value(): void
    {
        // Card 0029. The modelled house volatility is an INDEX figure, and an index has averaged
        // away the property-specific half of the risk. The engine widens it for a household whose
        // home is one property, which moves the fan on every homeowner plan, so the reader must
        // be told the figure, where it came from and that they can change it.
        $set = AssumptionSetLibrary::default();
        $disclosures = $this->disclosures(['salePrice' => '400000', 'annualRent' => '18000'], set: $set);

        $this->assertCount(1, $disclosures);
        $index = self::pct($set->houseGrowthVolatility?->asPercent() ?? 0.0);
        $property = self::pct($set->singlePropertyVolatility()?->asPercent() ?? 0.0);

        $this->assertStringContainsString("{$property}% a year", $disclosures[0], 'the widened figure actually used');
        $this->assertStringContainsString("{$index}%", $disclosures[0], 'and the index figure it was widened from');
    }

    public function test_nothing_is_assumed_about_property_volatility_when_the_reader_gave_a_figure(): void
    {
        // No noise, and no overriding: the reader's own figure is not one the engine supplied.
        $this->assertSame([], $this->disclosures(
            ['salePrice' => '400000', 'annualRent' => '18000'],
            set: AssumptionSetLibrary::default()->withSinglePropertyVolatility(Percent::fromPercent(12)),
        ));
    }

    public function test_nothing_is_assumed_about_property_volatility_on_a_plan_holding_no_home(): void
    {
        // A sell-and-rent plan owns no property for the widened spread to apply to, so telling its
        // reader what we assumed about one asserts a risk the model never charges them.
        $this->assertSame([], $this->disclosures(
            ['salePrice' => '400000', 'annualRent' => '18000'],
            set: AssumptionSetLibrary::default(),
            variant: 'rent',
        ));
    }

    public function test_the_assumed_letting_costs_are_disclosed_with_their_values(): void
    {
        // Card 0030. A let property used to earn its rent GROSS: no agent, no empty weeks, no
        // repairs. The engine now takes a quarter of the rent off for the reader, which changes
        // whether letting the home pays at all, so each rate must be on the screen with its value.
        $disclosures = $this->disclosures(
            ['salePrice' => '400000'],
            property: ['isLet' => true],
        );

        $this->assertCount(1, $disclosures);
        $this->assertStringContainsString(self::pct(Property::DEFAULT_LETTING_MANAGEMENT_BPS / 100).'% for letting-agent', $disclosures[0]);
        $this->assertStringContainsString(self::pct(Property::DEFAULT_LETTING_VOID_BPS / 100).'% for the weeks', $disclosures[0]);
        $this->assertStringContainsString(self::pct(Property::DEFAULT_LETTING_MAINTENANCE_BPS / 100).'% for repairs', $disclosures[0]);
    }

    public function test_only_the_letting_rates_the_reader_left_blank_are_reported_as_assumed(): void
    {
        // No noise, and no overriding: a landlord who manages the let themselves entered 0%, so the
        // engine must not claim to have assumed a management fee it never charged them.
        $disclosures = $this->disclosures(
            ['salePrice' => '400000'],
            property: ['isLet' => true, 'lettingManagementRate' => '0'],
        );

        $this->assertCount(1, $disclosures);
        $this->assertStringNotContainsString('letting-agent', $disclosures[0]);
        $this->assertStringContainsString(self::pct(Property::DEFAULT_LETTING_VOID_BPS / 100).'% for the weeks', $disclosures[0]);
    }

    public function test_nothing_is_assumed_about_letting_when_the_reader_gave_every_rate(): void
    {
        $this->assertSame([], $this->disclosures(
            ['salePrice' => '400000'],
            property: ['isLet' => true, 'lettingManagementRate' => '10', 'lettingVoidRate' => '4', 'lettingMaintenanceRate' => '6'],
        ));
    }

    public function test_nothing_is_assumed_about_letting_a_home_they_live_in(): void
    {
        // The deduction hangs off the let flag alone, so a residence must be told nothing about it.
        $this->assertSame([], $this->disclosures(['salePrice' => '400000']));
    }

    /** A rate as the disclosures write it: 18.0 -> "18", 2.50 -> "2.5". */
    private static function pct(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2), '0'), '.');
    }

    /**
     * The one disclosure that mentions $needle, asserting there is exactly one. Lets a test about
     * one default assert on it without depending on the order the others are listed in.
     *
     * @param  list<string>  $disclosures
     */
    private function only(array $disclosures, string $needle): string
    {
        $matched = array_values(array_filter($disclosures, static fn (string $d): bool => str_contains($d, $needle)));
        $this->assertCount(1, $matched, "exactly one disclosure should mention \"{$needle}\"");

        return $matched[0];
    }

    /**
     * Card 0038. The triple lock is a POLICY assumption, and the engine was making it silently:
     * `growState` raised the State Pension by the greater of inflation and 2.5% with nothing on
     * any screen. On the modelled inflation path the floor binds in most years, so the pension
     * grew in real terms for the whole plan and the Pension Credit guarantee rose with it.
     */
    public function test_the_state_pension_uprating_floor_is_disclosed_with_its_value(): void
    {
        $disclosures = $this->disclosures(
            ['salePrice' => '400000', 'annualRent' => '18000'],
            variant: 'rent',
            settings: new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
        );

        $note = $this->only($disclosures, 'triple lock');
        $this->assertStringContainsString(
            self::pct(StatePensionUprating::floor()->asPercent()).'%',
            $note,
            'the disclosed floor must be the one the engine actually applies',
        );
        // Why it applies: nobody chose it, and it is the optimistic branch.
        $this->assertStringContainsString('Pension Credit', $note, 'the benefit floor it also uprates');
    }

    public function test_nothing_is_assumed_about_uprating_when_the_reader_chose_a_basis(): void
    {
        // No noise, and no overriding: a reader who asked for prices alone is not being given a
        // figure the engine supplied, so nothing about the lock should be claimed as assumed.
        $disclosures = $this->disclosures(
            ['salePrice' => '400000', 'annualRent' => '18000'],
            variant: 'rent',
            settings: new ForecastSettings(
                baseYear: 2026, baseTaxYear: '2026-27',
                statePensionUprating: StatePensionUprating::Inflation,
            ),
        );

        $this->assertSame([], array_values(array_filter(
            $disclosures,
            static fn (string $d): bool => str_contains($d, 'triple lock'),
        )));
    }

    /**
     * Card 0038. The allocation is the single largest determinant of the answer and nothing ever
     * passes one, so every projection ever run has used a cautious 40/60 nobody was told about.
     * The weights and the return they buy are both READ from the engine.
     */
    public function test_the_assumed_portfolio_allocation_is_disclosed_with_its_weights(): void
    {
        $set = AssumptionSetLibrary::default();
        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
        $disclosures = $this->disclosures(
            ['salePrice' => '400000', 'annualRent' => '18000'],
            set: $set, variant: 'rent', settings: $settings,
        );

        $note = $this->only($disclosures, 'split');
        foreach ($settings->allocation()->weights as $i => $weight) {
            $this->assertStringContainsString(
                self::pct($weight * 100).'% '.mb_strtolower($set->assetClasses[$i]->name),
                $note,
                'every weight the engine actually uses must be named, with the asset class it belongs to',
            );
        }
        $this->assertStringContainsString(
            self::pct($settings->allocation()->blendedRealReturn($set) * 100).'%',
            $note,
            'and the blended real return those weights buy',
        );
    }

    public function test_nothing_is_assumed_about_an_allocation_the_caller_supplied(): void
    {
        $disclosures = $this->disclosures(
            ['salePrice' => '400000', 'annualRent' => '18000'],
            set: AssumptionSetLibrary::default(), variant: 'rent',
            settings: new ForecastSettings(
                baseYear: 2026, baseTaxYear: '2026-27',
                allocation: new PortfolioAllocation([0.6, 0.4, 0.0]),
            ),
        );

        $this->assertSame([], array_values(array_filter(
            $disclosures,
            static fn (string $d): bool => str_contains($d, 'split'),
        )));
    }

    /**
     * Card 0038. Every care figure is the engine's: the chance of needing care, how long it
     * lasts, how often it is nursing rather than residential, and what a week costs. They set
     * the size of the tail risk the whole care toggle exists to show, and none of them reached
     * a screen.
     */
    public function test_the_care_assumptions_are_disclosed_when_care_is_modelled(): void
    {
        $care = CareAssumptions::default();
        $disclosures = $this->disclosures(
            ['salePrice' => '400000', 'annualRent' => '18000'],
            variant: 'rent',
            settings: new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', modelCareCost: true),
        );

        // Card 0059 added a SECOND care disclosure beside the first, for the two NHS routes that
        // pay for care, so the care figures are read across both rather than out of one note.
        $matched = array_values(array_filter($disclosures, static fn (string $d): bool => str_contains($d, 'care')));
        $this->assertCount(2, $matched, 'the care assumptions and the NHS routes are each disclosed once');
        $note = implode(' ', $matched);

        foreach ([
            self::pct($care->probabilityOfCareMale * 100).'%',
            self::pct($care->probabilityOfCareFemale * 100).'%',
            self::pct($care->meanDurationYears).' years',
            (string) $care->maxDurationYears,
            self::pct($care->probabilityNursing * 100).'%',
            $care->residentialWeekly->format(),
            $care->nursingWeekly->format(),
            // The NHS contribution we take off the nursing fee, and the award that removes the
            // whole charge and which this engine does not model (card 0059).
            $care->fundedNursingCareWeekly()->format(),
            'Continuing Healthcare',
        ] as $figure) {
            $this->assertStringContainsString($figure, $note, "the disclosure must name {$figure}, which the sampler actually uses");
        }
    }

    public function test_nothing_is_assumed_about_care_when_care_is_not_modelled(): void
    {
        // No noise: with the care toggle off no care figure reaches a projection, so telling the
        // reader what we assumed about care asserts a cost the model never charges them.
        $disclosures = $this->disclosures(
            ['salePrice' => '400000', 'annualRent' => '18000'],
            variant: 'rent',
            settings: new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
        );

        $this->assertSame([], array_values(array_filter(
            $disclosures,
            static fn (string $d): bool => str_contains($d, 'care'),
        )));
    }

    /**
     * @param  list<array<string, mixed>>  $pensions
     * @return list<string>
     */
    private function disclosuresFor(array $pensions, string $dob = '1966-01-01', ?ForecastSettings $settings = null, bool $couple = false): array
    {
        $people = [['id' => 'p1', 'dob' => $dob, 'sex' => 'female', 'employmentStatus' => 'retired']];
        if ($couple) {
            $people[] = ['id' => 'p2', 'dob' => '1964-01-01', 'sex' => 'male', 'employmentStatus' => 'retired'];
        }

        $state = [
            'householdName' => 'Savers', 'region' => 'england_wales_ni', 'baseTaxYear' => '2026-27',
            'relationshipStatus' => 'married_or_civil_partnership',
            'people' => $people,
            'pensions' => $pensions,
            'accounts' => [['id' => 'a1', 'ownerId' => 'p1', 'type' => 'isa', 'balance' => '50000']],
            'incomeStreams' => [['id' => 'i1', 'ownerId' => 'p1', 'type' => 'other', 'grossAnnual' => '60000',
                'taxable' => true, 'inflationLinked' => false, 'startAge' => 0]],
            'expenseLines' => [['id' => 'e1', 'amount' => '18000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => false,
        ];

        $assembler = new HouseholdAssembler;
        $household = $assembler->household($state);
        $run = $settings ?? new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
        $forecast = (new DeterministicForecaster(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi),
            new CohortLifeTable,
        ))->forecast($household, AssumptionSetLibrary::default(), $run);

        return array_values(array_map(
            static fn (array $n): string => $n['text'],
            array_filter(
                ResultPresenter::inputNotes($household, $forecast, null, null, null, $settings),
                static fn (array $n): bool => $n['kind'] === 'assumed_figure',
            ),
        ));
    }

    /**
     * Board card 0057. The rate the person who INHERITS an unused pot pays on drawing it is a fact
     * about somebody outside the household, so it can only ever be assumed. It also sets half the
     * cost of preserving a pot rather than spending it, which is the whole point of the Inheritance
     * Tax toggle, so it cannot be assumed silently.
     */
    public function test_the_assumed_beneficiary_tax_rate_is_disclosed_with_its_value(): void
    {
        $disclosures = $this->disclosuresFor(
            [['id' => 'dc1', 'ownerId' => 'p1', 'subtype' => 'dc', 'currentValue' => '200000', 'earliestAccessAge' => '57']],
            settings: new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', modelIht: true),
        );

        $note = $this->only($disclosures, 'inherits');
        $this->assertStringContainsString(
            self::pct(InheritanceTaxCalculator::DEFAULT_BENEFICIARY_MARGINAL_RATE_BPS / 100).'%',
            $note,
            'the disclosed rate must be the constant the engine actually charges at',
        );
        $this->assertStringContainsString((string) InheritanceTaxCalculator::BENEFICIARY_TAXED_FROM_AGE, $note);
    }

    public function test_nothing_is_assumed_about_the_beneficiary_rate_when_the_reader_chose_one(): void
    {
        $disclosures = $this->disclosuresFor(
            [['id' => 'dc1', 'ownerId' => 'p1', 'subtype' => 'dc', 'currentValue' => '200000', 'earliestAccessAge' => '57']],
            settings: new ForecastSettings(
                baseYear: 2026, baseTaxYear: '2026-27', modelIht: true,
                beneficiaryMarginalRate: Percent::fromPercent(20),
            ),
        );

        $this->assertSame([], array_values(array_filter(
            $disclosures,
            static fn (string $d): bool => str_contains($d, 'inherits'),
        )));
    }

    public function test_nothing_is_assumed_about_a_beneficiary_when_inheritance_tax_is_not_modelled(): void
    {
        // No noise: with the toggle off no estate is valued and no beneficiary charge is computed.
        $disclosures = $this->disclosuresFor(
            [['id' => 'dc1', 'ownerId' => 'p1', 'subtype' => 'dc', 'currentValue' => '200000', 'earliestAccessAge' => '57']],
        );

        $this->assertSame([], array_values(array_filter(
            $disclosures,
            static fn (string $d): bool => str_contains($d, 'inherits'),
        )));
    }

    /**
     * Board card 0057. A pension death benefit is paid on the member's expression of wish, not
     * under the will, so an unanswered nomination is treated as NOT going to the spouse. That is
     * the adverse answer and it costs the first death real tax, so the reader has to be told the
     * tool made it for them.
     */
    public function test_an_unanswered_pension_nomination_is_disclosed(): void
    {
        $disclosures = $this->disclosuresFor(
            [['id' => 'dc1', 'ownerId' => 'p1', 'subtype' => 'dc', 'currentValue' => '200000', 'earliestAccessAge' => '57']],
            settings: new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', modelIht: true),
            couple: true,
        );

        $note = $this->only($disclosures, 'nominated');
        $this->assertStringContainsString('expression of wish', $note);
    }

    public function test_nothing_is_assumed_about_a_nomination_the_reader_gave(): void
    {
        $disclosures = $this->disclosuresFor(
            [['id' => 'dc1', 'ownerId' => 'p1', 'subtype' => 'dc', 'currentValue' => '200000',
                'earliestAccessAge' => '57', 'nominatedBeneficiary' => 'spouse_or_civil_partner']],
            settings: new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', modelIht: true),
            couple: true,
        );

        $this->assertSame([], array_values(array_filter(
            $disclosures,
            static fn (string $d): bool => str_contains($d, 'nominated'),
        )));
    }

    public function test_the_money_purchase_annual_allowance_is_disclosed_when_the_plan_triggers_it(): void
    {
        // Taking money flexibly out of a pension caps what may be paid back into one for the rest
        // of the plan. Nobody enters that cap and it shrinks what the contributions they DID enter
        // buy — and the only screen that ever mentioned it needs a PLANNED withdrawal instruction,
        // which a draw taken to meet a shortfall is not. So it applied and nothing said so.
        $disclosures = $this->disclosuresFor([[
            'id' => 'dc1', 'ownerId' => 'p1', 'subtype' => 'dc', 'currentValue' => '200000',
            'ongoingContribution' => '20000', 'earliestAccessAge' => '55',
            'withdrawals' => [['kind' => 'ufpls', 'amount' => '10000', 'atAge' => '61']],
        ]]);

        $this->assertCount(1, $disclosures);
        $this->assertStringContainsString(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi)->pension->moneyPurchaseAnnualAllowance->format(),
            $disclosures[0],
            'the disclosed cap must be the statutory figure the engine actually applies',
        );
        // p1 is 60 in 2026, so the instruction at 61 fires in 2027 — the reader is told when.
        $this->assertStringContainsString('starts in 2027', $disclosures[0]);
    }

    public function test_nothing_is_disclosed_about_the_mpaa_when_nothing_is_being_paid_in(): void
    {
        // No noise: a cap on what may be paid INTO a pension changes nothing for a member paying
        // nothing in, so the same withdrawal on a pot with no contributions discloses nothing.
        $this->assertSame([], $this->disclosuresFor([[
            'id' => 'dc1', 'ownerId' => 'p1', 'subtype' => 'dc', 'currentValue' => '200000',
            'earliestAccessAge' => '55',
            'withdrawals' => [['kind' => 'ufpls', 'amount' => '10000', 'atAge' => '61']],
        ]]));
    }

    public function test_an_assumed_fixed_db_escalation_rate_is_disclosed_with_its_value(): void
    {
        // A scheme set to a FIXED increase with no rate entered takes the engine's default, and
        // that rate compounds on guaranteed income for thirty years. Board card 0035 added the
        // input; without this the default would be exactly the invisible figure the rule bans.
        $disclosures = $this->disclosuresFor([[
            'id' => 'db1', 'ownerId' => 'p1', 'subtype' => 'db', 'accruedAnnualPension' => '12000',
            'normalRetirementAge' => '65', 'revaluationBasis' => 'cpi', 'escalationInPayment' => 'fixed',
        ]]);

        $this->assertCount(1, $disclosures);
        $this->assertStringContainsString(
            self::pct(DbPension::DEFAULT_FIXED_ESCALATION_BPS / 100).'%',
            $disclosures[0],
            'the disclosed rate must be the one the engine actually escalates at',
        );
    }

    public function test_nothing_is_assumed_about_a_fixed_rate_the_reader_entered(): void
    {
        $this->assertSame([], $this->disclosuresFor([[
            'id' => 'db1', 'ownerId' => 'p1', 'subtype' => 'db', 'accruedAnnualPension' => '12000',
            'normalRetirementAge' => '65', 'revaluationBasis' => 'cpi', 'escalationInPayment' => 'fixed',
            'fixedEscalationRate' => '5',
        ]]));
    }

    public function test_nothing_is_assumed_about_a_scheme_that_is_not_on_a_fixed_basis(): void
    {
        // No noise: a default that never applies is not disclosed.
        $this->assertSame([], $this->disclosuresFor([[
            'id' => 'db1', 'ownerId' => 'p1', 'subtype' => 'db', 'accruedAnnualPension' => '12000',
            'normalRetirementAge' => '65', 'revaluationBasis' => 'cpi', 'escalationInPayment' => 'cpi_capped_5',
        ]]));
    }

    public function test_an_rpi_basis_discloses_that_the_model_treats_it_as_cpi(): void
    {
        // The engine models no RPI-over-CPI wedge (PensionEscalationBasis::RPI_OVER_CPI_WEDGE_BPS).
        // A reader who picked RPI and was told nothing would reasonably believe the model heard
        // them, which is the exact failure board card 0035 was raised for.
        $disclosures = $this->disclosuresFor([[
            'id' => 'db1', 'ownerId' => 'p1', 'subtype' => 'db', 'accruedAnnualPension' => '12000',
            'normalRetirementAge' => '65', 'revaluationBasis' => 'rpi', 'escalationInPayment' => 'rpi',
        ]]);

        $this->assertCount(1, $disclosures);
        $this->assertStringContainsString('RPI', $disclosures[0]);
        $this->assertStringContainsString('CPI', $disclosures[0]);
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
