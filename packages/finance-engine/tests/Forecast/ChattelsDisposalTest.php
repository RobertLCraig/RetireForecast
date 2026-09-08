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
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\PathProjector;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Board card 0065, criterion #3. Selling personal possessions was modelled as money arriving from
 * nowhere with no tax on it, so a plan that turns on selling the art or the jewellery was
 * optimistic by the whole capital-gains bill. The receipt now carries what the item COST, which is
 * what makes the disposal chargeable, and the charge shares the year's annual exempt amount with
 * every other disposal exactly as a share sale does.
 */
final class ChattelsDisposalTest extends TestCase
{
    private function forecaster(): DeterministicForecaster
    {
        return new DeterministicForecaster(TaxYearRegistry::for('2026-27'), new CohortLifeTable);
    }

    /** No inflation and no growth, so every figure below is exact in nominal AND real pence. */
    private function flatAssumptions(): AssumptionSet
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

    private function settings(): ForecastSettings
    {
        return new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
    }

    /**
     * A couple, both 68 in the 2026 base year, on two full State Pensions with £15,000 of essential
     * spend, so nothing is drawn and the only disposal in the year is the one under test.
     */
    private function couple(?CapitalReceipt $receipt): Household
    {
        return new Household(
            'Chattels', RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(15_000), Money::zero(), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
            ],
            capitalReceipts: $receipt === null ? [] : [$receipt],
        );
    }

    /** The 2026 base year of a plan whose only event is p2 selling something for $pricePounds. */
    private function saleYear(int $pricePounds, ?Money $cost): YearResult
    {
        return $this->forecaster()->forecast(
            $this->couple(new CapitalReceipt('p2', 'A painting', Money::fromPounds($pricePounds), 2026, chattelCost: $cost)),
            $this->flatAssumptions(),
            $this->settings(),
        )->years[0];
    }

    public function test_selling_a_chattel_above_the_threshold_is_charged_capital_gains_tax(): void
    {
        // A painting sold for £60,000 that cost £5,000: a £55,000 gain, well past the point where
        // the marginal relief stops biting. The expectation is COMPUTED from the band rule and the
        // statutory figures that own them, never restated here, so a re-sourced figure moves it.
        $config = TaxYearRegistry::for('2026-27');
        $statePension = Money::of(241, 30)->pence * 52;
        $expected = PathProjector::cgtOnGain(
            5_500_000,
            $statePension,
            $config->cgt->annualExemptAmount->pence,
            $config->incomeTax->personalAllowance->pence,
            $config->incomeTax->basicRateBand->pence,
            $config->cgt->residentialBasicRate,
            $config->cgt->residentialHigherRate,
        );
        $this->assertGreaterThan(0, $expected, 'the fixture must produce a real charge to be worth testing');

        $untaxed = $this->saleYear(60_000, null);
        $charged = $this->saleYear(60_000, Money::fromPounds(5_000));

        $this->assertSame($untaxed->totalTax->pence + $expected, $charged->totalTax->pence);

        // The sale proceeds still arrive in full: the tax is a cost of the year, not a haircut on
        // the receipt, so the cashflow ladder still reconciles.
        $this->assertSame(6_000_000, $charged->incomeBySource['capital_receipt']->pence);
    }

    public function test_selling_a_chattel_inside_the_threshold_is_charged_nothing(): void
    {
        // £5,000 for something that cost £500 is a real £4,500 gain and not a chargeable one.
        $untaxed = $this->saleYear(5_000, null);
        $charged = $this->saleYear(5_000, Money::fromPounds(500));

        $this->assertSame($untaxed->totalTax->pence, $charged->totalTax->pence);
    }

    public function test_a_receipt_that_is_not_a_disposal_is_still_charged_nothing(): void
    {
        // An inheritance or a family gift has no acquisition cost to state, so it is not a
        // disposal by the receiver and must stay untaxed, which is the pre-card behaviour.
        $gift = $this->saleYear(60_000, null);
        $nothing = $this->forecaster()->forecast($this->couple(null), $this->flatAssumptions(), $this->settings())->years[0];

        $this->assertSame($nothing->totalTax->pence, $gift->totalTax->pence);
    }
}
