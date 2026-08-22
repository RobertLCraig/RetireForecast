<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\IncomeStream;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\PensionReliefMethod;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\PensionParameters;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The two limits on money going into a pension that the engine used to ignore, and the route a
 * member with no earnings still has (adviser-parity A2, the half left open on 2026-07-31).
 *
 *  - **The annual allowance.** Until this work only the MPAA capped contributions, and only after
 *    flexible access; before that the projector paid in any amount asked for. A household told to
 *    save GBP 80,000 a year into a pension got relief on all of it, which the law does not give.
 *  - **The MPAA**, unchanged, still replaces the annual allowance once a pension has been
 *    flexibly accessed.
 *  - **The GBP 3,600 "basic amount".** A member with no relevant UK earnings can still pay GBP
 *    2,880 and have the provider add basic-rate relief, so GBP 3,600 lands in the pot. It is the
 *    commonly missed move for a retired spouse, and the engine could not express it at all.
 *
 * Both caps count the EMPLOYER's contribution as well as the member's, because the statutory
 * allowance is measured on total pension input rather than on what the household paid.
 */
final class ContributionAllowanceTest extends TestCase
{
    private function flat(): AssumptionSet
    {
        // Zero returns everywhere, so the pot at the end of a year IS what was paid into it and
        // an assertion can be made to the penny.
        return new AssumptionSet(
            name: 'flat', sourceNote: 'test',
            assetClasses: [
                new AssetClassAssumption('Equity', Percent::zero(), Percent::zero()),
                new AssetClassAssumption('Bond', Percent::zero(), Percent::zero()),
                new AssetClassAssumption('Cash', Percent::zero(), Percent::zero()),
            ],
            correlationMatrix: [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]],
            inflationMean: Percent::zero(), inflationVolatility: Percent::zero(),
            houseGrowth: Percent::zero(), rentInflation: Percent::zero(),
            salaryGrowth: Percent::zero(), investmentIncomeYield: Percent::zero(),
        );
    }

    private function project(Household $household): ForecastResult
    {
        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, $this->flat(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
    }

    private function pension(): PensionParameters
    {
        return TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi)->pension;
    }

    /** A high earner paying $memberPounds of their own and $employerPounds of the employer's. */
    private function earner(int $memberPounds, int $employerPounds = 0): Household
    {
        return new Household(
            'Big saver',
            RegionProfile::EnglandWalesNi,
            [new Person(
                'p1', new DateTimeImmutable('1976-01-01'), Sex::Male, EmploymentStatus::Employed,
                grossSalary: Money::fromPounds(180_000), plannedRetirementAge: 60,
                longevity: LongevityAdjustment::fixedAge(62),
            )],
            new ExpenseProfile(Money::fromPounds(20_000), Money::zero(), Percent::fromPercent(100)),
            pensions: [new DcPension(
                'p1', Money::zero(), Money::fromPounds($memberPounds), Money::fromPounds($employerPounds), 57,
                reliefMethod: PensionReliefMethod::NetPay,
            )],
        );
    }

    /** A retired member with no earnings at all, using the basic-amount route. */
    private function nonEarner(int $netContributionPounds, string $dob = '1955-01-01'): Household
    {
        return new Household(
            'Retired saver',
            RegionProfile::EnglandWalesNi,
            [new Person(
                'p1', new DateTimeImmutable($dob), Sex::Female, EmploymentStatus::Retired,
                longevity: LongevityAdjustment::fixedAge(90),
            )],
            new ExpenseProfile(Money::fromPounds(12_000), Money::zero(), Percent::fromPercent(100)),
            pensions: [new DcPension(
                'p1', Money::zero(), Money::fromPounds($netContributionPounds), Money::zero(), 55,
                reliefMethod: PensionReliefMethod::NonEarner,
            )],
            incomeStreams: [new IncomeStream('p1', IncomeStreamType::Other, Money::fromPounds(30_000), true, false, 0)],
        );
    }

    public function test_a_contribution_above_the_annual_allowance_is_capped_at_it(): void
    {
        // GBP 80,000 asked for out of a GBP 180,000 salary. Only the annual allowance may go in.
        $year = $this->project($this->earner(80_000))->years[0];

        $this->assertSame($this->pension()->annualAllowance->pence, $year->pensionWealth->pence);
    }

    public function test_a_contribution_within_the_annual_allowance_is_untouched(): void
    {
        // The cap must bite only where it should: GBP 30,000 is well inside it and goes in whole.
        $year = $this->project($this->earner(30_000))->years[0];

        $this->assertSame(Money::fromPounds(30_000)->pence, $year->pensionWealth->pence);
    }

    public function test_the_employers_contribution_counts_against_the_same_allowance(): void
    {
        // GBP 40,000 each is GBP 80,000 of pension input against one GBP 60,000 allowance.
        // Measuring the cap on the member's share alone would let the pair through untouched.
        $year = $this->project($this->earner(40_000, 40_000))->years[0];

        $this->assertSame($this->pension()->annualAllowance->pence, $year->pensionWealth->pence);
    }

    public function test_what_the_allowance_blocks_stays_with_the_household_rather_than_vanishing(): void
    {
        // Completeness: money refused by the pot is not destroyed. A net-pay contribution the
        // allowance blocks is never given up, so it stays in pay, is taxed there like any other
        // earnings, and what survives is saved. Asking for GBP 80,000 must therefore leave the
        // household in precisely the position of one that asked for the allowance and no more,
        // pot AND pocket. A cap that dropped the excess would show here as the same pot beside a
        // smaller pocket.
        $capped = $this->project($this->earner(80_000))->years[0];
        $atCap = $this->project($this->earner(60_000))->years[0];

        $this->assertSame($atCap->pensionWealth->pence, $capped->pensionWealth->pence);
        $this->assertSame($atCap->liquidWealth->pence, $capped->liquidWealth->pence);
        $this->assertSame($atCap->totalTax->pence, $capped->totalTax->pence);
        $this->assertGreaterThan(0, $capped->liquidWealth->pence, 'the blocked money is still the household\'s');
    }

    public function test_the_non_earner_route_grosses_a_net_payment_up_by_basic_rate_relief(): void
    {
        // GBP 2,880 leaves the bank, the provider reclaims 20%, and GBP 3,600 lands in the pot:
        // the whole point of the route, and something no other relief method here can do.
        $year = $this->project($this->nonEarner(2_880))->years[0];

        $this->assertSame($this->pension()->nonEarnerReliefLimit->pence, $year->pensionWealth->pence);
    }

    public function test_the_non_earner_route_is_capped_at_the_basic_amount(): void
    {
        // Asking for more does not buy more relief: relief above the basic amount needs net pay.
        // The pot still receives exactly GBP 3,600.
        $year = $this->project($this->nonEarner(10_000))->years[0];

        $this->assertSame($this->pension()->nonEarnerReliefLimit->pence, $year->pensionWealth->pence);
    }

    public function test_the_net_cost_to_the_household_is_the_net_payment_not_the_gross(): void
    {
        // The relief is real money from HMRC, not the household's: GBP 3,600 arrives in the pot
        // having cost them GBP 2,880. Compared against the same household contributing nothing.
        $with = $this->project($this->nonEarner(2_880))->years[0];
        $without = $this->project($this->nonEarner(0))->years[0];

        $this->assertSame(
            Money::fromPounds(2_880)->pence,
            $without->liquidWealth->pence - $with->liquidWealth->pence,
            'only the net payment leaves the household',
        );
        $this->assertSame(
            Money::fromPounds(720)->pence,
            $with->totalWealth->pence - $without->totalWealth->pence,
            'and the household is better off by exactly the relief',
        );
    }

    public function test_relief_stops_at_seventy_five_so_the_contribution_stops_with_it(): void
    {
        // Relief on a member's own contributions ends at 75. A route that ran on regardless would
        // hand the household a fifth of nothing for the rest of the plan.
        $year = $this->project($this->nonEarner(2_880, dob: '1948-01-01'))->years[0];

        $this->assertSame(0, $year->pensionWealth->pence);
    }

    public function test_the_limits_are_the_sourced_registry_figures_not_literals(): void
    {
        // Read the record that owns each figure, so a statutory change moves the cap and this
        // test together rather than leaving one behind.
        $pension = $this->pension();

        $this->assertSame(Money::fromPounds(60_000)->pence, $pension->annualAllowance->pence);
        $this->assertSame(Money::fromPounds(10_000)->pence, $pension->moneyPurchaseAnnualAllowance->pence);
        $this->assertSame(Money::fromPounds(3_600)->pence, $pension->nonEarnerReliefLimit->pence);
        $this->assertSame(75, $pension->reliefMaximumAge);
    }
}
