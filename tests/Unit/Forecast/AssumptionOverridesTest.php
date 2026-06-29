<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\AssumptionOverrides;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Forecast\PortfolioAllocation;

/**
 * The user's editable assumptions, applied onto a sourced preset to derive the custom set
 * the forecast runs. The trust-critical properties: with no overrides the preset is returned
 * unchanged (reconciliation — an unedited custom set IS the preset), a filled figure reaches
 * the engine set exactly (an "investment growth = X%" edit lands the blended return on X), and
 * only filled, known keys are applied so an untouched figure keeps following the preset.
 */
final class AssumptionOverridesTest extends TestCase
{
    private PortfolioAllocation $allocation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocation = PortfolioAllocation::cautious40_60();
    }

    public function test_no_overrides_returns_the_preset_unchanged(): void
    {
        $base = AssumptionSetLibrary::default();
        $derived = AssumptionOverrides::apply($base, [], $this->allocation);

        // Reconciliation: every economic figure is identical to the preset.
        $this->assertSame($this->allocation->blendedRealReturn($base), $this->allocation->blendedRealReturn($derived));
        $this->assertSame($base->inflationMean->basisPoints, $derived->inflationMean->basisPoints);
        $this->assertSame($base->houseGrowth->basisPoints, $derived->houseGrowth->basisPoints);
        $this->assertSame($base->rentInflation->basisPoints, $derived->rentInflation->basisPoints);
        $this->assertSame($base->salaryGrowth->basisPoints, $derived->salaryGrowth->basisPoints);
        $this->assertSame($base->investmentIncomeYield->basisPoints, $derived->investmentIncomeYield->basisPoints);
    }

    public function test_blank_and_unknown_keys_are_ignored(): void
    {
        $base = AssumptionSetLibrary::default();
        $derived = AssumptionOverrides::apply(
            $base,
            ['investmentGrowth' => '', 'inflation' => null, 'somethingElse' => '9'],
            $this->allocation,
        );

        $this->assertSame($base->inflationMean->basisPoints, $derived->inflationMean->basisPoints);
        $this->assertSame($this->allocation->blendedRealReturn($base), $this->allocation->blendedRealReturn($derived));
    }

    public function test_an_investment_growth_edit_lands_the_blended_return_on_the_target(): void
    {
        $base = AssumptionSetLibrary::default();

        // The user wants 3% real growth; the derived set's blended return must be 3%.
        $derived = AssumptionOverrides::apply($base, ['investmentGrowth' => '3'], $this->allocation);
        $this->assertEqualsWithDelta(0.03, $this->allocation->blendedRealReturn($derived), 1e-4);

        // It holds under a different allocation too (the shift is allocation-aware).
        $allEquities = new PortfolioAllocation([1.0, 0.0, 0.0]);
        $derivedEq = AssumptionOverrides::apply($base, ['investmentGrowth' => '6'], $allEquities);
        $this->assertEqualsWithDelta(0.06, $allEquities->blendedRealReturn($derivedEq), 1e-4);

        // The non-return figures are untouched by an investment-growth edit.
        $this->assertSame($base->inflationMean->basisPoints, $derived->inflationMean->basisPoints);
    }

    public function test_each_scalar_figure_reaches_the_set(): void
    {
        $base = AssumptionSetLibrary::default();
        $derived = AssumptionOverrides::apply(
            $base,
            [
                'inflation' => '3.5',
                'houseGrowth' => '2',
                'rentGrowth' => '1.25',
                'salaryGrowth' => '0.5',
                'incomeYield' => '2.8',
            ],
            $this->allocation,
        );

        $this->assertSame(350, $derived->inflationMean->basisPoints);
        $this->assertSame(200, $derived->houseGrowth->basisPoints);
        $this->assertSame(125, $derived->rentInflation->basisPoints);
        $this->assertSame(50, $derived->salaryGrowth->basisPoints);
        $this->assertSame(280, $derived->investmentIncomeYield->basisPoints);
    }

    public function test_preset_figures_surface_the_presets_own_values(): void
    {
        $figures = AssumptionOverrides::presetFigures(AssumptionSetLibrary::default(), $this->allocation);

        // FCA default under cautious 40/60: blend 1.76%, CPI 2%, house 1%, rent 0.5%, salary 1%, yield 2%.
        $this->assertSame('1.76', $figures['investmentGrowth']);
        $this->assertSame('2', $figures['inflation']);
        $this->assertSame('1', $figures['houseGrowth']);
        $this->assertSame('0.5', $figures['rentGrowth']);
        $this->assertSame('1', $figures['salaryGrowth']);
        $this->assertSame('2', $figures['incomeYield']);
    }

    public function test_sparse_and_changed_keys_keep_only_filled_known_figures(): void
    {
        $raw = ['investmentGrowth' => '3', 'inflation' => '', 'houseGrowth' => null, 'rentGrowth' => '2', 'junk' => '9'];

        $this->assertSame(['investmentGrowth' => '3', 'rentGrowth' => '2'], AssumptionOverrides::sparse($raw));
        $this->assertSame(['investmentGrowth', 'rentGrowth'], AssumptionOverrides::changedKeys($raw));
        $this->assertSame([], AssumptionOverrides::changedKeys([]));
    }
}
