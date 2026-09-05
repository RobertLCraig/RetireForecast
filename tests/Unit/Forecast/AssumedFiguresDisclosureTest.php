<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use App\Forecast\ResultPresenter;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
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
    private function disclosures(array $housing, string $currentRunningCosts = '', string $accountType = 'isa', ?array $expenseLines = null, ?string $propertyCostsGrowthPct = null): array
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

    /**
     * @param  list<array<string, mixed>>  $pensions
     * @return list<string>
     */
    private function disclosuresFor(array $pensions, string $dob = '1966-01-01'): array
    {
        $state = [
            'householdName' => 'Savers', 'region' => 'england_wales_ni', 'baseTaxYear' => '2026-27',
            'people' => [['id' => 'p1', 'dob' => $dob, 'sex' => 'female', 'employmentStatus' => 'retired']],
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
        $forecast = (new DeterministicForecaster(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi),
            new CohortLifeTable,
        ))->forecast($household, AssumptionSetLibrary::default(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));

        return array_values(array_map(
            static fn (array $n): string => $n['text'],
            array_filter(
                ResultPresenter::inputNotes($household, $forecast, null),
                static fn (array $n): bool => $n['kind'] === 'assumed_figure',
            ),
        ));
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
