<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\MonteCarlo;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Care\CareCostSampler;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\PortfolioAllocation;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\MonteCarlo\ReturnModel;
use RetireForecast\FinanceEngine\MonteCarlo\Simulator;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Mortality\JointLifeSampler;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The Monte Carlo golden master: ONE frozen household, frozen settings and a frozen seed,
 * against expected values pinned to the penny.
 *
 * WHY THIS FILE EXISTS. The other reproducibility tests run the simulator twice in the same
 * process and assert the two agree, which is true of any deterministic function and catches
 * nothing. The property the PRD actually claims is that a given seed keeps producing the SAME
 * numbers over time, and only a pinned expectation can fail when it stops. Three places consume
 * the shared stream and can silently re-roll every stored result if their draw order moves:
 * {@see ReturnModel::generatePath()} (per year: assets, inflation, house, salary),
 * {@see JointLifeSampler::sampleDeathAge()} (a VARIABLE number of draws, one per year of life)
 * and {@see CareCostSampler}. Care is modelled on here so all three sit in the stream.
 *
 * WHEN THIS TEST GOES RED. It is not a flake and it must not be re-pinned quietly. Something
 * moved the numbers, and it is one of three things:
 *   1. a draw was added, removed or reordered in any of the three samplers above,
 *   2. the projector's arithmetic changed (which is the ENGINE_VERSION bump conversation), or
 *   3. an economic figure moved in {@see AssumptionSetLibrary::default()}.
 * All three re-roll every stored result. If the change is deliberate, re-pin PINNED below, bump
 * {@see self::PIN_REVISION} to today, and append the matching entry to docs/DECISIONS.md.
 * The second test in this file will stay red until that entry exists, which is what makes the
 * decision log a requirement rather than a request.
 *
 * The pinned figures are integer pence and fixed-precision probability strings, so nothing here
 * turns on float formatting. They are still the product of floating-point maths on one machine:
 * a different libm could in principle move a penny. That has not been seen, and a whole-penny
 * drift would be a real signal rather than noise.
 */
final class GoldenMasterTest extends TestCase
{
    /**
     * The date the values below were last pinned. Bump it in the same edit that re-pins any of
     * them, and write the DECISIONS.md entry it then demands.
     */
    private const PIN_REVISION = '2026-09-08';

    /** The exact phrase docs/DECISIONS.md has to carry, so a re-pin cannot be recorded by accident. */
    private const MARKER = 'Monte Carlo golden master pinned ';

    private const SEED = 4242;

    private const PATHS = 200;

    /**
     * The expected run, to the penny. Money is integer pence; probabilities are formatted to
     * four decimals, which resolves a single path in 200 (0.005).
     *
     * @var array<string, int|string>
     */
    private const PINNED = [
        'successProbabilityEssentials' => '0.5050',
        'successProbabilityFullSpend' => '0.0600',
        'terminalWealth.p10' => 3796678,
        'terminalWealth.p25' => 19404532,
        'terminalWealth.p50' => 37288927,
        'terminalWealth.p75' => 71090928,
        'terminalWealth.p90' => 111656875,
        'fan.bands' => 38,
        'fan.first.calendarYear' => 2026,
        'fan.first.p10' => 69036832,
        'fan.first.p50' => 69058862,
        'fan.first.p90' => 69091951,
        'fan.middle.calendarYear' => 2045,
        'fan.middle.p10' => 16185116,
        'fan.middle.p50' => 44220549,
        'fan.middle.p90' => 103268481,
        'fan.last.calendarYear' => 2063,
        'fan.last.p10' => 9578042,
        'fan.last.p50' => 16349924,
        'fan.last.p90' => 23121806,
    ];

    public function test_the_pinned_run_still_produces_the_pinned_numbers(): void
    {
        $result = (new Simulator(TaxYearRegistry::for('2026-27')))->run(
            $this->frozenHousehold(),
            $this->frozenSettings(),
            AssumptionSetLibrary::default(),
            new CohortLifeTable,
            self::PATHS,
            self::SEED,
        );

        $first = $result->fanChart[0];
        $middle = $result->fanChart[intdiv(count($result->fanChart), 2)];
        $last = $result->fanChart[count($result->fanChart) - 1];

        $actual = [
            'successProbabilityEssentials' => sprintf('%.4f', $result->successProbabilityEssentials),
            'successProbabilityFullSpend' => sprintf('%.4f', $result->successProbabilityFullSpend),
            'terminalWealth.p10' => $result->terminalWealthPercentiles['p10']->pence,
            'terminalWealth.p25' => $result->terminalWealthPercentiles['p25']->pence,
            'terminalWealth.p50' => $result->terminalWealthPercentiles['p50']->pence,
            'terminalWealth.p75' => $result->terminalWealthPercentiles['p75']->pence,
            'terminalWealth.p90' => $result->terminalWealthPercentiles['p90']->pence,
            'fan.bands' => count($result->fanChart),
            'fan.first.calendarYear' => $first['calendarYear'],
            'fan.first.p10' => $first['p10']->pence,
            'fan.first.p50' => $first['p50']->pence,
            'fan.first.p90' => $first['p90']->pence,
            'fan.middle.calendarYear' => $middle['calendarYear'],
            'fan.middle.p10' => $middle['p10']->pence,
            'fan.middle.p50' => $middle['p50']->pence,
            'fan.middle.p90' => $middle['p90']->pence,
            'fan.last.calendarYear' => $last['calendarYear'],
            'fan.last.p10' => $last['p10']->pence,
            'fan.last.p50' => $last['p50']->pence,
            'fan.last.p90' => $last['p90']->pence,
        ];

        $this->assertSame(
            self::PINNED,
            $actual,
            'The fixed-seed Monte Carlo no longer reproduces its pinned run, so every stored '
            .'result has silently re-rolled. Read this file\'s docblock before re-pinning: a '
            ."re-pin needs PIN_REVISION bumped and a docs/DECISIONS.md entry, or the next test fails.\n",
        );
    }

    public function test_the_pinned_run_is_recorded_in_the_decision_log(): void
    {
        $log = dirname(__DIR__, 4).'/docs/DECISIONS.md';
        $this->assertFileExists($log, 'the golden master is only honest while the decision log is reachable from it');

        $this->assertStringContainsString(
            self::MARKER.self::PIN_REVISION,
            (string) file_get_contents($log),
            'Nothing in docs/DECISIONS.md records the Monte Carlo pin at revision '.self::PIN_REVISION
            .". Re-pinning the golden master moves every stored result, so it is a decision and not a\n"
            .'tidy-up: append an entry containing "'.self::MARKER.self::PIN_REVISION.'" saying what moved and why.',
        );
    }

    /**
     * The frozen input. It is deliberately declared here rather than shared with the app's
     * HouseholdFixture: that fixture moves whenever a builder field is added (it is paired with
     * BuilderStateFixture for the round-trip tests), and a golden master whose input drifts pins
     * nothing. Nothing about this household may change. Model a different one in a new test.
     *
     * One person still working (so the salary draw is consumed), one retired, a home with no
     * growth override (so the house draw is consumed), sampled mortality on both (so the
     * variable-length death draws are consumed) and care modelled (so the care draws are).
     */
    private function frozenHousehold(): Household
    {
        return new Household(
            name: 'Golden master couple',
            region: RegionProfile::EnglandWalesNi,
            persons: [
                new Person(
                    id: 'p1',
                    dob: new DateTimeImmutable('1959-03-15'),
                    sex: Sex::Female,
                    employmentStatus: EmploymentStatus::Employed,
                    grossSalary: Money::fromPounds(42_000),
                    salaryGrowth: Percent::fromPercent(2),
                    plannedRetirementAge: 67,
                    niCategory: 'A',
                ),
                new Person(
                    id: 'p2',
                    dob: new DateTimeImmutable('1955-07-08'),
                    sex: Sex::Male,
                    employmentStatus: EmploymentStatus::Retired,
                ),
            ],
            expenseProfile: new ExpenseProfile(
                essentialAnnualSpend: Money::fromPounds(26_000),
                discretionaryAnnualSpend: Money::fromPounds(8_000),
                survivorSpendFactor: Percent::fromPercent(70),
            ),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(221, 20)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(221, 20)),
                new DcPension(
                    ownerId: 'p1',
                    currentValue: Money::fromPounds(280_000),
                    ongoingContribution: Money::fromPounds(6_000),
                    employerContribution: Money::fromPounds(3_000),
                    earliestAccessAge: 57,
                ),
            ],
            accounts: [
                new Account('p1', AccountType::Isa, Money::fromPounds(60_000), yield: Percent::fromPercent(3)),
                new Account('p2', AccountType::Cash, Money::fromPounds(15_000)),
            ],
            primaryResidence: new Property(
                currentValue: Money::fromPounds(340_000),
                ownership: OwnershipType::Outright,
                runningCosts: Money::fromPounds(3_000),
            ),
        );
    }

    /** Frozen settings. The allocation is stated rather than defaulted, so the pin owns its inputs. */
    private function frozenSettings(): ForecastSettings
    {
        return new ForecastSettings(
            baseYear: 2026,
            baseTaxYear: '2026-27',
            allocation: new PortfolioAllocation([0.40, 0.60, 0.0]),
            modelCareCost: true,
        );
    }
}
