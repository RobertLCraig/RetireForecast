<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Housing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Reconciliation invariant for the housing boundary: a home sale must decompose into
 * net proceeds plus the costs netted off it, with no pence created or lost. This is
 * the forecast-boundary half of the data-layer integrity rule — net sale proceeds ==
 * sale − mortgage − costs − CGT — pinned so a future change (e.g. a real CGT charge or
 * the SDLT surcharge) can't silently break the identity.
 */
final class HousingProceedsReconciliationTest extends TestCase
{
    private function comparison(): HousingComparison
    {
        return new HousingComparison(TaxYearRegistry::for('2026-27'), new CohortLifeTable);
    }

    private function household(?Money $mortgage = null): Household
    {
        return new Household(
            'Reconcile',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(20_000), Money::fromPounds(2_000), Percent::fromPercent(70)),
            primaryResidence: new Property(
                currentValue: Money::fromPounds(400_000),
                ownership: OwnershipType::Outright,
                outstandingMortgage: $mortgage,
            ),
        );
    }

    public function test_sale_price_reconciles_to_net_proceeds_plus_its_deductions(): void
    {
        $action = new HousingAction(salePrice: Money::fromPounds(400_000));
        $proceeds = $this->comparison()->saleProceeds($this->household(Money::fromPounds(50_000)), $action);

        // The sale price is exactly the cash kept plus every cost deducted — no pence created or lost.
        $this->assertSame(
            $proceeds->salePrice->pence,
            $proceeds->netProceeds->pence
                + $proceeds->outstandingMortgage->pence
                + $proceeds->sellingCosts->pence
                + $proceeds->capitalGainsTax->pence,
        );
        $this->assertTrue($proceeds->clearsCosts());
    }

    public function test_main_home_cgt_is_zero_under_prr(): void
    {
        $proceeds = $this->comparison()->saleProceeds($this->household(), new HousingAction(salePrice: Money::fromPounds(400_000)));

        $this->assertSame(0, $proceeds->capitalGainsTax->pence);
    }

    public function test_selling_costs_apply_the_default_two_percent(): void
    {
        $proceeds = $this->comparison()->saleProceeds($this->household(), new HousingAction(salePrice: Money::fromPounds(400_000)));

        // 2% of £400,000 = £8,000; net = 400,000 − 0 − 8,000 − 0.
        $this->assertSame(Money::fromPounds(8_000)->pence, $proceeds->sellingCosts->pence);
        $this->assertSame(Money::fromPounds(392_000)->pence, $proceeds->netProceeds->pence);
    }

    public function test_a_custom_selling_cost_rate_is_honoured(): void
    {
        $action = new HousingAction(salePrice: Money::fromPounds(400_000), sellingCostRate: Percent::fromPercent(3));
        $proceeds = $this->comparison()->saleProceeds($this->household(), $action);

        $this->assertSame(Money::fromPounds(12_000)->pence, $proceeds->sellingCosts->pence);
    }

    public function test_negative_equity_floors_net_proceeds_at_zero(): void
    {
        // Mortgage exceeds the sale price: there is nothing to keep, and never a negative.
        $action = new HousingAction(salePrice: Money::fromPounds(400_000));
        $proceeds = $this->comparison()->saleProceeds($this->household(Money::fromPounds(450_000)), $action);

        $this->assertSame(0, $proceeds->netProceeds->pence);
        $this->assertFalse($proceeds->clearsCosts());
    }
}
