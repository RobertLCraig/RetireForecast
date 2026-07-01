<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * When one partner of a couple dies, the survivor inherits the deceased's assets. Without
 * this the deceased's savings, investments and pension were stranded — summed into wealth
 * but never drawable (the drawdown skips a dead owner) — which read falsely as "essentials
 * not met / ran out of money" from the very first death, even with a full pot sitting there.
 *
 * The invariants proved here: the survivor can actually SPEND the inherited liquid assets (no
 * spurious depletion at the death year), and the pension transfer respects ownership — only
 * the deceased's own pot passes, so the survivor's own pot is neither lost nor double-counted.
 */
final class EstateInheritanceTest extends TestCase
{
    private function forecast(Household $h): ForecastResult
    {
        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($h, AssumptionSetLibrary::default(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
    }

    public function test_the_survivor_can_spend_a_deceased_partners_savings(): void
    {
        // P1 (older) holds ALL the cash and dies in 2029; P2 survives to 2050. After P1 dies the
        // household must draw that cash to meet essentials — so it has to pass to P2, not freeze.
        // Without inheritance essentials would fail the year P1 dies with £70k+ sitting idle; with
        // it, essentials stay met and the cash draws down for real until it genuinely runs out.
        $household = new Household(
            'Inherit',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1950-01-01'), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(78)),   // dies 2029
                new Person('p2', new DateTimeImmutable('1960-01-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(90)), // dies 2050
            ],
            new ExpenseProfile(Money::fromPounds(25_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(150, 0)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(150, 0)),
            ],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(100_000))], // all in P1's name
        );

        $forecast = $this->forecast($household);
        $usable = [];
        $met = [];
        foreach ($forecast->years as $y) {
            $usable[$y->calendarYear] = $y->liquidWealth->plus($y->pensionWealth)->pence;
            $met[$y->calendarYear] = $y->essentialsMet;
        }

        // P1 dies 2029. The survivor keeps meeting essentials by drawing the inherited cash —
        // no spurious "ran out" at the death year.
        $this->assertTrue($met[2030], 'survivor should meet essentials from the inherited cash');
        $this->assertTrue($met[2031]);
        $this->assertNotSame(2029, $forecast->depletionCalendarYear);
        $this->assertNotSame(2030, $forecast->depletionCalendarYear);

        // The inherited cash is genuinely SPENT (usable wealth falls year on year after the death),
        // not frozen at a constant balance the way stranded assets were.
        $this->assertGreaterThan($usable[2031], $usable[2030]);
        $this->assertGreaterThan($usable[2032], $usable[2031]);
    }

    public function test_pension_inheritance_conserves_the_pot_and_respects_ownership(): void
    {
        // A single £200k DC pot must end up as the SAME household pension wealth whether it was
        // owned by the survivor (P1) or by the partner who dies (P2) — proving the pot is neither
        // lost when its owner dies (it passes to the survivor) nor duplicated onto a pot the
        // survivor already had (Rob's no-double-dip). Spend sits below income so the pot is never
        // drawn, only grows — isolating the transfer.
        $withPot = fn (string $potOwner): Household => new Household(
            'Ownership',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-01-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(95)), // survives
                new Person('p2', new DateTimeImmutable('1952-01-01'), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(76)),   // dies 2029
            ],
            new ExpenseProfile(Money::fromPounds(12_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(200, 0)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(200, 0)),
                new DcPension(
                    ownerId: $potOwner,
                    currentValue: Money::fromPounds(200_000),
                    ongoingContribution: Money::zero(),
                    employerContribution: Money::zero(),
                    earliestAccessAge: 57,
                    withdrawalPlan: [],
                ),
            ],
        );

        $pensionIn = function (Household $h, int $year): int {
            foreach ($this->forecast($h)->years as $y) {
                if ($y->calendarYear === $year) {
                    return $y->pensionWealth->pence;
                }
            }
            self::fail("no year $year");
        };

        // 2031 is after P2's death (2029). Same figure both ways: not lost, not doubled.
        $ownedByP1 = $pensionIn($withPot('p1'), 2031);
        $ownedByP2 = $pensionIn($withPot('p2'), 2031);
        $this->assertEqualsWithDelta($ownedByP1, $ownedByP2, Money::fromPounds(1)->pence, 'the pot must be conserved, not lost or double-dipped, whoever owned it');
        $this->assertGreaterThan(Money::fromPounds(150_000)->pence, $ownedByP2, 'a deceased owner\'s pot passes to the survivor, not lost');
    }
}
