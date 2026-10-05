<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Board card 0024, criterion 4. Only the mortgage PAYMENT is held flat in cash and charged to a
 * survivor whole. Every other line that runs "only while the mortgage runs" (a mortgage life or
 * protection premium, a fee, a voluntary overpayment) still rises with prices and stays in the
 * tier the reader put it in, and still stops when the mortgage does. Built from builder state,
 * because the fault was in how the assembler filled the bucket, not in the engine's arithmetic.
 */
final class MortgageLinkedCostsTest extends TestCase
{
    public function test_a_discretionary_mortgage_line_never_strips_the_essential_floor(): void
    {
        // £10,000 of food and heat, plus a £12,000 voluntary overpayment the reader put under
        // nice-to-haves. The floor is £10,000; it must not read £12,000 with the food gone.
        $year = $this->years([
            ['id' => 'e1', 'label' => 'Food and heat', 'amount' => '10000', 'category' => 'essential'],
            ['id' => 'd1', 'label' => 'Mortgage overpayment', 'amount' => '12000', 'category' => 'discretionary'],
        ], inflationPercent: 0)[2026];

        $this->assertSame(Money::fromPounds(10_000)->pence, $year->essentialSpend->pence);
        $this->assertSame(Money::fromPounds(22_000)->pence, $year->spendTarget->pence);
    }

    public function test_a_mortgage_protection_premium_keeps_rising_with_prices(): void
    {
        // A premium is not interest on a fixed balance: it rises with prices, so twenty years on
        // it is still about £1,200 in today's money, not the £664 a frozen cash figure would be.
        $lines = [
            ['id' => 'e1', 'label' => 'Food and heat', 'amount' => '10000', 'category' => 'essential'],
            ['id' => 'e2', 'label' => 'Mortgage', 'amount' => '6000', 'category' => 'essential'],
        ];
        $without = $this->years($lines, inflationPercent: 3);
        $with = $this->years([...$lines, ['id' => 'e3', 'label' => 'Mortgage protection insurance', 'amount' => '1200', 'category' => 'essential']], inflationPercent: 3);

        $this->assertEqualsWithDelta(
            Money::fromPounds(1_200)->pence,
            $with[2046]->essentialSpend->pence - $without[2046]->essentialSpend->pence,
            Money::fromPounds(5)->pence,
        );
    }

    public function test_a_mortgage_linked_line_still_stops_when_the_mortgage_ends_and_leaves_its_own_tier(): void
    {
        // Redeemed from capital in 2030: the payment, the premium and the overpayment all stop,
        // and each leaves the tier it sat in. Flat economy, so real == nominal.
        $years = $this->years([
            ['id' => 'e1', 'label' => 'Food and heat', 'amount' => '10000', 'category' => 'essential'],
            ['id' => 'e2', 'label' => 'Mortgage', 'amount' => '6000', 'category' => 'essential'],
            ['id' => 'e3', 'label' => 'Mortgage protection insurance', 'amount' => '1200', 'category' => 'essential'],
            ['id' => 'd1', 'label' => 'Mortgage overpayment', 'amount' => '2000', 'category' => 'discretionary'],
        ], inflationPercent: 0, redemptionYear: 2030);

        $this->assertSame(Money::fromPounds(9_200)->pence, $years[2029]->spendTarget->pence - $years[2031]->spendTarget->pence);
        $this->assertSame(Money::fromPounds(7_200)->pence, $years[2029]->essentialSpend->pence - $years[2031]->essentialSpend->pence);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<int, YearResult> calendarYear => year
     */
    private function years(array $lines, int $inflationPercent, ?int $redemptionYear = null): array
    {
        // A couple, both kept alive past the horizon so no survivor factor can move a figure (a
        // single person is charged the survivor factor from year 0), with enough cash that no
        // year runs short and a redemption is funded cleanly.
        $household = (new HouseholdAssembler)->household([
            'householdName' => 'Mortgage linked', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired', 'longevityMode' => 'fixed_age', 'longevityValue' => '100'],
                ['id' => 'p2', 'dob' => '1958-01-01', 'sex' => 'male', 'employmentStatus' => 'retired', 'longevityMode' => 'fixed_age', 'longevityValue' => '100'],
            ],
            'pensions' => [
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230'],
                ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '230'],
            ],
            'accounts' => [['id' => 'a1', 'ownerId' => 'p1', 'type' => 'cash', 'balance' => '600000']],
            'expenseLines' => $lines,
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => true,
            'property' => array_filter([
                'currentValue' => '400000', 'ownership' => 'mortgaged', 'outstandingMortgage' => '100000',
                'mortgageRedemptionYear' => $redemptionYear === null ? null : (string) $redemptionYear,
                'mortgageMaturityAction' => $redemptionYear === null ? null : 'repay_from_capital',
            ]),
        ]);

        $forecast = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast(
                $household,
                AssumptionSetLibrary::default()->withInflationMean(Percent::fromPercent($inflationPercent)),
                new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            );

        $years = [];
        foreach ($forecast->years as $year) {
            $years[$year->calendarYear] = $year;
        }

        return $years;
    }
}
