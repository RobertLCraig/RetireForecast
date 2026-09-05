<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Housing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\IncomeStream;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\PathProjector;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * A year-0 purchase savings draw that sells GIA holdings is a REAL disposal: the engine
 * charges CGT on the realised gain in year 0 ({@see Household::$realisedGainsAtStart}),
 * sharing the annual exempt amount once with any in-year disposal — taxed exactly once,
 * never twice, never free. Expected figures are computed through the engine's own public
 * primitives ({@see PathProjector::disposeGiaSlice} / {@see PathProjector::cgtOnGain}), so
 * the assertions track the single tax definition rather than a hand-rounded copy.
 */
final class PurchaseSavingsCgtTest extends TestCase
{
    /** Zero growth + zero inflation, so nominal == real and every figure is the entered value. */
    private function flat(): AssumptionSet
    {
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

    /**
     * Taxable income £X gross; a £100k GIA carrying a £40k unrealised gain; a £400k outright
     * home. Selling (net £392k) and buying £430k (+£11.5k SDLT +£2k moving) leaves a £51,500
     * gap, funded entirely from the GIA — realising a £20,600 pro-rata gain at the base date.
     */
    private function household(int $incomePounds): Household
    {
        return new Household(
            'Year0Cgt',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(17_114), Money::fromPounds(2_000), Percent::fromPercent(100)),
            accounts: [new Account('p1', AccountType::Gia, Money::fromPounds(100_000), unrealisedGain: Money::fromPounds(40_000))],
            incomeStreams: [new IncomeStream('p1', IncomeStreamType::Other, Money::fromPounds($incomePounds), true, false, 60)],
            primaryResidence: new Property(currentValue: Money::fromPounds(400_000), ownership: OwnershipType::Outright),
        );
    }

    private function buyForecast(int $incomePounds): ForecastResult
    {
        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
        $action = new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(430_000));
        $config = TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi);
        $buy = (new HousingComparison($config, new CohortLifeTable))
            ->variantInputs($this->household($incomePounds), $settings, $this->flat(), $action)['buy_outright']['household'];

        return (new DeterministicForecaster($config, new CohortLifeTable))->forecast($buy, $this->flat(), $settings);
    }

    /** CGT on a gain for a person with the given taxable income, via the engine's own band split. */
    private function cgt(int $gainPence, int $incomePence): int
    {
        $config = TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi);

        return PathProjector::cgtOnGain(
            $gainPence,
            $incomePence,
            $config->cgt->annualExemptAmount->pence,
            $config->incomeTax->personalAllowance->pence,
            $config->incomeTax->basicRateBand->pence,
            $config->cgt->residentialBasicRate,
            $config->cgt->residentialHigherRate,
        );
    }

    public function test_year_zero_cgt_is_charged_on_the_gain_the_purchase_draw_realises(): void
    {
        // Income £31,000 → £3,686 income tax, £27,314 net. Ordinary spend = £17,114 + £2,000 +
        // £4,300 running costs (1% of £430k) = £23,414, so the year-0 surplus (£3,900) covers
        // the CGT bill in cash — no in-year disposal muddies the figure.
        $seedGain = PathProjector::disposeGiaSlice(100_000_00, 60_000_00, 59_500_00)[0];
        $this->assertSame(23_800_00, $seedGain, 'the £59,500 draw realises the pro-rata slice of the £40k gain');
        $seedCgt = $this->cgt($seedGain, 31_000_00);
        // £23,800 gain − £3,000 AEA = £20,800 taxable. £19,270 of basic band is left at this
        // income (£50,270 − £31,000), taxed at 18%; the £1,530 over the threshold is at 24%.
        $this->assertSame(3_835_80, $seedCgt);

        $forecast = $this->buyForecast(31_000);

        $incomeTax = 3_686_00; // (31,000 − 12,570) × 20%
        $this->assertSame($incomeTax + $seedCgt, $forecast->years[0]->totalTax->pence, 'year 0 pays income tax + the disposal CGT');
        $this->assertSame($incomeTax, $forecast->years[1]->totalTax->pence, 'later years pay income tax only');
        $this->assertTrue($forecast->fullSpendAlwaysMet, 'the CGT is paid from the year-0 surplus, not unmet');
    }

    public function test_the_annual_exempt_amount_is_shared_once_between_the_seed_and_an_in_year_disposal(): void
    {
        // Income £20,000 → £1,486 tax, £18,514 net. After the seed CGT the year-0 cash cannot
        // cover the £23,414 spend, so the shortfall is funded by a further in-year GIA draw —
        // realising more gain on top of the seed. The combined CGT must equal cgtOnGain(seed +
        // in-year gain): ONE annual exempt amount across both, the seed never taxed twice.
        $incomeTax = 1_486_00; // (20,000 − 12,570) × 20%
        $seedGain = PathProjector::disposeGiaSlice(100_000_00, 60_000_00, 59_500_00)[0];
        $seedCgt = $this->cgt($seedGain, 20_000_00);

        // The in-year draw = spend − (net income − seed CGT), taken from the post-draw GIA
        // (£40,500 balance, £16,200 gain remaining, so a £24,300 cost basis).
        $take = 23_414_00 - (18_514_00 - $seedCgt);
        $inYearGain = PathProjector::disposeGiaSlice(40_500_00, 24_300_00, $take)[0];

        $forecast = $this->buyForecast(20_000);

        $this->assertSame(
            $incomeTax + $this->cgt($seedGain + $inYearGain, 20_000_00),
            $forecast->years[0]->totalTax->pence,
            'combined CGT is banded on the combined gain — one AEA, no double charge',
        );
    }
}
