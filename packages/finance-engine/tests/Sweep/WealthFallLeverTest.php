<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Sweep;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\DbPension;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\MortgageRatePeriod;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\RepaymentMortgageTerms;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Sweep\Lever\WealthFallLever;
use RetireForecast\FinanceEngine\Sweep\LeverDirection;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;

/**
 * The capacity-for-loss lever. The property that makes the answer reportable is
 * **reconciliation**: a fall of f must take exactly f of total wealth, measured on the project's
 * one definition (liquid + money-purchase pots + home equity net of the mortgage). If it did not,
 * the percentage on screen and the pounds beside it would be two different quantities.
 *
 * The rest of the tests are the places that arithmetic goes wrong: the mortgage must NOT fall with
 * the house, a home already under water has nothing left to lose, defined-benefit income is not a
 * pot to mark down, and the household's beneficial share must be applied exactly once.
 */
final class WealthFallLeverTest extends TestCase
{
    private function settings(): ForecastSettings
    {
        return new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
    }

    public function test_a_fall_takes_exactly_that_fraction_of_total_wealth(): void
    {
        $household = $this->household();
        $lever = new WealthFallLever;

        // £100k ISA + £200k pot + (£400k home − £100k mortgage) = £600,000.
        $wealth = WealthFallLever::baseWealth($household, $this->settings());
        $this->assertSame(600_000_00, $wealth->pence);

        foreach ([0.0, 0.25, 0.5, 1.0] as $fall) {
            $fallen = $lever->apply($household, $this->settings(), $fall)->household;

            $this->assertSame(
                (int) round(600_000_00 * (1 - $fall)),
                WealthFallLever::baseWealth($fallen, $this->settings())->pence,
                "a {$fall} fall did not take exactly that share of total wealth",
            );
        }
    }

    public function test_the_mortgage_does_not_fall_with_the_house(): void
    {
        $fallen = (new WealthFallLever)->apply($this->household(), $this->settings(), 0.5)->household;
        $home = $fallen->primaryResidence;

        $this->assertNotNull($home);
        $this->assertSame(100_000_00, $home->outstandingMortgage?->pence, 'the debt is unchanged by a fall in the asset');
        // Equity was £300k; half of it is gone, so the house is worth the loan plus £150k.
        $this->assertSame(250_000_00, $home->currentValue->pence);
    }

    public function test_a_home_worth_less_than_its_loan_has_nothing_left_to_lose(): void
    {
        $household = $this->household(homeValue: 80_000, mortgage: 120_000);

        // The No-Negative-Equity floor already holds the equity at zero, so the home is all debt.
        $this->assertSame(300_000_00, WealthFallLever::baseWealth($household, $this->settings())->pence);

        $fallen = (new WealthFallLever)->apply($household, $this->settings(), 0.5)->household;
        $this->assertSame(80_000_00, $fallen->primaryResidence?->currentValue->pence, 'an under-water home is left alone');
        $this->assertSame(150_000_00, WealthFallLever::baseWealth($fallen, $this->settings())->pence);
    }

    public function test_income_pensions_are_not_a_pot_to_mark_down(): void
    {
        $household = $this->household();
        $fallen = (new WealthFallLever)->apply($household, $this->settings(), 0.5)->household;

        $db = array_values(array_filter($fallen->pensions, fn ($p): bool => $p instanceof DbPension));
        $this->assertCount(1, $db);
        $this->assertSame(9_000_00, $db[0]->accruedAnnualPension->pence, 'a defined-benefit income is not wealth that can fall');

        $dc = array_values(array_filter($fallen->pensions, fn ($p): bool => $p instanceof DcPension));
        $this->assertSame(100_000_00, $dc[0]->currentValue->pence);
        $this->assertSame(57, $dc[0]->earliestAccessAge, 'the rest of the pot survives the rebuild');
    }

    public function test_an_unrealised_gain_falls_with_the_balance_and_never_goes_negative(): void
    {
        $household = $this->household(gain: 30_000);
        $lever = new WealthFallLever;

        // A 10% fall on a £100k GIA loses £10k of cash, so £10k of the gain goes with it.
        $tenth = $lever->apply($household, $this->settings(), 0.1)->household->accounts[0];
        $this->assertSame(90_000_00, $tenth->balance->pence);
        $this->assertSame(20_000_00, $tenth->unrealisedGain?->pence);

        // A fall larger than the gain leaves no gain — never a loss this engine cannot use.
        $half = $lever->apply($household, $this->settings(), 0.5)->household->accounts[0];
        $this->assertSame(0, $half->unrealisedGain?->pence);
    }

    public function test_a_part_owned_home_counts_and_falls_by_its_share_only(): void
    {
        $household = $this->household(share: 50);

        // Half of (£400k − £100k) = £150k of equity, plus £300k of savings and pot.
        $this->assertSame(450_000_00, WealthFallLever::baseWealth($household, $this->settings())->pence);

        $fallen = (new WealthFallLever)->apply($household, $this->settings(), 0.2)->household;
        $this->assertSame(360_000_00, WealthFallLever::baseWealth($fallen, $this->settings())->pence);
    }

    public function test_an_amortising_mortgage_is_read_at_its_base_year_balance(): void
    {
        // A £200k loan first paid in 2016 has been amortising for ten years, so far less than
        // £200k is owed by the base year — and equity is correspondingly larger.
        $terms = new RepaymentMortgageTerms(
            termMonths: 300,
            firstPaymentYear: 2016,
            firstPaymentMonth: 1,
            ratePeriods: [new MortgageRatePeriod(Percent::fromPercent(4), null)],
        );
        $household = $this->household(mortgage: 200_000, repaymentTerms: $terms);

        $wealth = WealthFallLever::baseWealth($household, $this->settings());
        $this->assertGreaterThan(
            500_000_00,
            $wealth->pence,
            'ten years of capital repayment is equity the household owns',
        );

        $fallen = (new WealthFallLever)->apply($household, $this->settings(), 0.25)->household;
        $this->assertSame(
            (int) round($wealth->pence * 0.75),
            WealthFallLever::baseWealth($fallen, $this->settings())->pence,
        );
        $this->assertNotNull($fallen->primaryResidence?->repaymentTerms, 'the amortisation schedule survives the re-valuation');
    }

    public function test_the_lever_declares_itself_monotone_and_clamps(): void
    {
        $lever = new WealthFallLever;
        $this->assertSame(LeverDirection::Decreasing, $lever->direction());

        $this->assertSame(
            WealthFallLever::baseWealth($this->household(), $this->settings())->pence,
            WealthFallLever::baseWealth($lever->apply($this->household(), $this->settings(), -3.0)->household, $this->settings())->pence,
            'a negative fall is clamped to none, never a windfall',
        );
        $this->assertSame(
            0,
            WealthFallLever::baseWealth($lever->apply($this->household(), $this->settings(), 4.0)->household, $this->settings())->pence,
            'a fall beyond everything is clamped to everything',
        );
    }

    /** A retired couple with savings, one money-purchase pot, one DB income and a mortgaged home. */
    private function household(
        int $homeValue = 400_000,
        int $mortgage = 100_000,
        ?int $gain = null,
        ?int $share = null,
        ?RepaymentMortgageTerms $repaymentTerms = null,
    ): Household {
        return new Household(
            'Fall',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1957-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(28_000), Money::fromPounds(4_000), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds(200)),
                new DbPension('p2', Money::fromPounds(9_000), 65),
                new DcPension('p1', Money::fromPounds(200_000), Money::zero(), Money::zero(), earliestAccessAge: 57),
            ],
            [
                new Account(
                    'p1',
                    $gain === null ? AccountType::Isa : AccountType::Gia,
                    Money::fromPounds(100_000),
                    $gain === null ? null : Money::fromPounds($gain),
                ),
            ],
            primaryResidence: new Property(
                Money::fromPounds($homeValue),
                OwnershipType::Mortgaged,
                outstandingMortgage: Money::fromPounds($mortgage),
                ownershipShare: $share === null ? null : Percent::fromPercent($share),
                repaymentTerms: $repaymentTerms,
            ),
        );
    }
}
