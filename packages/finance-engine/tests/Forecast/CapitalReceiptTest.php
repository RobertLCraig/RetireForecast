<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\CapitalReceipt;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\IncomeStream;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Sweep\Lever\EssentialSpendLever;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * A documented one-off capital receipt (a family gift / inheritance / outside-asset sale —
 * {@see CapitalReceipt}) must demonstrably reach the forecast: credited in exactly its
 * calendar year, visible on the cashflow ladder, tax-free, disregarded as means-test income
 * (but caught by the capital tariff from the following year), received by the household even
 * if the named owner has died, and carried intact through the sweep levers' household
 * rebuilds. The per-source completeness guard for money arriving from outside the plan.
 */
final class CapitalReceiptTest extends TestCase
{
    private function flat(float $inflationPct = 0.0): AssumptionSet
    {
        return new AssumptionSet(
            name: 'flat', sourceNote: 'test',
            assetClasses: [
                new AssetClassAssumption('Equity', Percent::zero(), Percent::zero()),
                new AssetClassAssumption('Bond', Percent::zero(), Percent::zero()),
                new AssetClassAssumption('Cash', Percent::zero(), Percent::zero()),
            ],
            correlationMatrix: [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]],
            inflationMean: Percent::fromPercent($inflationPct), inflationVolatility: Percent::zero(),
            houseGrowth: Percent::zero(), rentInflation: Percent::zero(),
            salaryGrowth: Percent::zero(), investmentIncomeYield: Percent::zero(),
        );
    }

    /**
     * @param  list<CapitalReceipt>  $receipts
     * @param  list<Person>|null  $persons
     */
    private function household(array $receipts, int $incomePounds = 27_000, int $essentialPounds = 19_114, ?array $persons = null): Household
    {
        $persons ??= [new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired)];

        return new Household(
            'Receipts',
            RegionProfile::EnglandWalesNi,
            $persons,
            new ExpenseProfile(Money::fromPounds($essentialPounds), Money::zero(), Percent::fromPercent(100)),
            incomeStreams: [new IncomeStream('p1', IncomeStreamType::Other, Money::fromPounds($incomePounds), true, false, 60)],
            capitalReceipts: $receipts,
        );
    }

    private function forecast(Household $household, float $inflationPct = 0.0): ForecastResult
    {
        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, $this->flat($inflationPct), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
    }

    /** @return array<int, YearResult> */
    private function byYear(ForecastResult $forecast): array
    {
        $out = [];
        foreach ($forecast->years as $year) {
            $out[$year->calendarYear] = $year;
        }

        return $out;
    }

    public function test_a_receipt_lands_in_exactly_its_year_tax_free_and_raises_wealth_by_its_amount(): void
    {
        $receipt = new CapitalReceipt('p1', 'Family gift', Money::fromPounds(50_000), 2028);
        $with = $this->byYear($this->forecast($this->household([$receipt])));
        $without = $this->byYear($this->forecast($this->household([])));

        // Visible on the ladder in exactly its year, nowhere else.
        $this->assertSame(Money::fromPounds(50_000)->pence, $with[2028]->incomeBySource['capital_receipt']->pence);
        $this->assertSame(0, $with[2027]->incomeBySource['capital_receipt']->pence);
        $this->assertSame(0, $with[2029]->incomeBySource['capital_receipt']->pence);

        // Completeness: from the receipt year on, liquid wealth is higher by exactly the
        // amount (the household's other flows are identical by construction).
        $this->assertSame(0, $with[2027]->liquidWealth->pence - $without[2027]->liquidWealth->pence);
        foreach ([2028, 2029, 2030] as $year) {
            $this->assertSame(
                Money::fromPounds(50_000)->pence,
                $with[$year]->liquidWealth->pence - $without[$year]->liquidWealth->pence,
                "the banked receipt persists in {$year}",
            );
        }

        // A gift is not income: no tax difference in any year.
        foreach ($with as $year => $result) {
            $this->assertSame($without[$year]->totalTax->pence, $result->totalTax->pence, "tax-free in {$year}");
        }
    }

    public function test_a_receipt_is_reported_in_todays_money_under_inflation(): void
    {
        // With 10% inflation the receipt is credited at its year's prices internally, but the
        // ladder reports real (today's) money — so the entered amount is what the reader sees.
        $receipt = new CapitalReceipt('p1', 'Family gift', Money::fromPounds(50_000), 2028);
        $with = $this->byYear($this->forecast($this->household([$receipt]), inflationPct: 10.0));

        $this->assertSame(Money::fromPounds(50_000)->pence, $with[2028]->incomeBySource['capital_receipt']->pence);
    }

    public function test_pension_credit_is_unchanged_in_the_receipt_year_but_eroded_by_the_tariff_after(): void
    {
        // A low-income retiree on Pension Credit: £10k taxable income, £8k spend. The receipt
        // is NOT assessable income (no change in its own year — opening capital is equal), but
        // the banked £50k raises tariff income from the next year, eroding the award — the
        // downsizing-trap mechanic, applied honestly to gifts too.
        $receipt = new CapitalReceipt('p1', 'Family gift', Money::fromPounds(50_000), 2028);
        $with = $this->byYear($this->forecast($this->household([$receipt], incomePounds: 10_000, essentialPounds: 8_000)));
        $without = $this->byYear($this->forecast($this->household([], incomePounds: 10_000, essentialPounds: 8_000)));

        $this->assertGreaterThan(0, $without[2029]->incomeBySource['means_tested_benefit']->pence, 'the baseline household is on Pension Credit');
        $this->assertSame(
            $without[2028]->incomeBySource['means_tested_benefit']->pence,
            $with[2028]->incomeBySource['means_tested_benefit']->pence,
            'the receipt is not assessable income in its own year',
        );
        $this->assertLessThan(
            $without[2029]->incomeBySource['means_tested_benefit']->pence,
            $with[2029]->incomeBySource['means_tested_benefit']->pence,
            'the banked capital erodes the award from the following year (tariff income)',
        );
    }

    public function test_a_receipt_whose_owner_has_died_still_reaches_the_household(): void
    {
        // p2 dies at 70 (2028, fixed); their gift arrives 2030. Inflows pool at household
        // level, so the survivor still receives it — the money does not vanish with the owner.
        $persons = [
            new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
            new Person('p2', new DateTimeImmutable('1958-04-01'), Sex::Male, EmploymentStatus::Retired,
                longevity: LongevityAdjustment::fixedAge(70)),
        ];
        $receipt = new CapitalReceipt('p2', 'Inheritance', Money::fromPounds(50_000), 2030);
        $with = $this->byYear($this->forecast($this->household([$receipt], persons: $persons)));
        $without = $this->byYear($this->forecast($this->household([], persons: $persons)));

        $this->assertSame(Money::fromPounds(50_000)->pence, $with[2030]->incomeBySource['capital_receipt']->pence);
        $this->assertSame(
            Money::fromPounds(50_000)->pence,
            $with[2030]->liquidWealth->pence - $without[2030]->liquidWealth->pence,
        );
    }

    public function test_a_receipt_after_the_last_death_is_never_realised(): void
    {
        // The sole person dies at 70 (2028); a receipt dated 2035 falls after the projection
        // ends — it must simply never appear (and never crash), not resurrect the household.
        $persons = [
            new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired,
                longevity: LongevityAdjustment::fixedAge(70)),
        ];
        $forecast = $this->forecast($this->household(
            [new CapitalReceipt('p1', 'Too late', Money::fromPounds(50_000), 2035)],
            persons: $persons,
        ));

        $total = 0;
        foreach ($forecast->years as $year) {
            $total += $year->incomeBySource['capital_receipt']->pence;
        }
        $this->assertSame(0, $total, 'a receipt after the last death is never realised');
    }

    public function test_receipts_survive_a_sweep_lever_household_rebuild(): void
    {
        // The levers rebuild Household positionally; a missed parameter silently drops the
        // receipts (and the year-0 realised gains). One representative lever guards them all.
        $receipt = new CapitalReceipt('p1', 'Family gift', Money::fromPounds(50_000), 2028);
        $household = $this->household([$receipt]);

        $applied = (new EssentialSpendLever)->apply(
            $household,
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            15_000,
        )->household;

        $this->assertCount(1, $applied->capitalReceipts);
        $this->assertSame($receipt->amount->pence, $applied->capitalReceipts[0]->amount->pence);
    }
}
