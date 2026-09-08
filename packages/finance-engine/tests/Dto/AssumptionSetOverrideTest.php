<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Dto;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Forecast\PortfolioAllocation;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * The immutable `with*` derivations that let a user tune an assumption set into a custom
 * one. The trust-critical properties: a derivation changes only its own field, the risk
 * structure (volatilities, correlations) is left alone, and every derivation returns a new
 * set without mutating the original, so a derived custom set never corrupts the shared preset.
 *
 * A uniform real-return shift USED to live here, as the route a user's "investment growth"
 * edit took. Board card 0062 deleted it: it raised the return of every asset class and left
 * the volatilities exactly where they were, so the edit bought return without buying risk.
 * The blended return now moves by re-weighting the mix, which is pinned in the engine's
 * PortfolioAllocationTest.
 */
final class AssumptionSetOverrideTest extends TestCase
{
    public function test_the_shipped_default_blends_to_its_stated_return(): void
    {
        $base = AssumptionSetLibrary::default();

        // Cautious 40/60 default blend: 0.40 × 4.4% + 0.60 × 0.0% = 1.76% real.
        $this->assertEqualsWithDelta(0.0176, PortfolioAllocation::cautious40_60()->blendedRealReturn($base), 1e-9);
    }

    public function test_each_scalar_field_can_be_replaced_independently(): void
    {
        $base = AssumptionSetLibrary::default();

        $this->assertSame(350, $base->withInflationMean(Percent::fromPercent(3.5))->inflationMean->basisPoints);
        $this->assertSame(250, $base->withHouseGrowth(Percent::fromPercent(2.5))->houseGrowth->basisPoints);
        $this->assertSame(125, $base->withRentInflation(Percent::fromPercent(1.25))->rentInflation->basisPoints);
        $this->assertSame(200, $base->withSalaryGrowth(Percent::fromPercent(2.0))->salaryGrowth->basisPoints);
        $this->assertSame(300, $base->withInvestmentIncomeYield(Percent::fromPercent(3.0))->investmentIncomeYield->basisPoints);
    }

    public function test_a_derivation_changes_only_its_field_and_carries_provenance_through(): void
    {
        $base = AssumptionSetLibrary::default();
        $derived = $base->withInflationMean(Percent::fromPercent(4.0));

        // Only inflation moved; every other figure (and the name/source/default flag) carries through.
        $this->assertSame(400, $derived->inflationMean->basisPoints);
        $this->assertSame($base->name, $derived->name);
        $this->assertSame($base->sourceNote, $derived->sourceNote);
        $this->assertSame($base->isDefault, $derived->isDefault);
        $this->assertSame($base->houseGrowth->basisPoints, $derived->houseGrowth->basisPoints);
        $this->assertSame($base->salaryGrowth->basisPoints, $derived->salaryGrowth->basisPoints);
        $this->assertSame($base->correlationMatrix, $derived->correlationMatrix);
        $this->assertSame($base->inflationVolatility->basisPoints, $derived->inflationVolatility->basisPoints);
    }

    public function test_the_original_set_is_never_mutated(): void
    {
        $base = AssumptionSetLibrary::default();
        $beforeInflation = $base->inflationMean->basisPoints;
        $beforeFirstReturn = $base->assetClasses[0]->expectedRealReturn->basisPoints;

        $base->withInflationMean(Percent::fromPercent(9.0));
        $base->withAssetClasses([]);

        $this->assertSame($beforeInflation, $base->inflationMean->basisPoints);
        $this->assertSame($beforeFirstReturn, $base->assetClasses[0]->expectedRealReturn->basisPoints);
    }
}
