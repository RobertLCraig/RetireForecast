<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\AssumptionOverrides;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Forecast\AllocationProfile;
use RetireForecast\FinanceEngine\Forecast\PortfolioAllocation;

/**
 * The user's editable assumptions, applied onto a sourced preset to derive the custom set
 * the forecast runs. The trust-critical properties: with no overrides the preset is returned
 * unchanged (reconciliation: an unedited custom set IS the preset), a filled figure reaches the
 * engine set exactly, and only filled, known keys are applied so an untouched figure keeps
 * following the preset. Investment growth is the exception that proves it: it lands on its
 * target by moving the asset MIX and never the set (board card 0062).
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
        $derived = AssumptionOverrides::apply($base, []);

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
        );

        $this->assertSame($base->inflationMean->basisPoints, $derived->inflationMean->basisPoints);
        $this->assertSame($this->allocation->blendedRealReturn($base), $this->allocation->blendedRealReturn($derived));
    }

    /**
     * Board card 0062. An investment-growth edit lands on its target by RE-WEIGHTING the mix,
     * and touches the assumption set not at all: the asset classes it is blended from keep the
     * preset's own returns and the preset's own volatilities, and the risk moves with the return.
     */
    public function test_an_investment_growth_edit_re_weights_the_mix_and_leaves_the_asset_classes_alone(): void
    {
        $base = AssumptionSetLibrary::default();
        $derived = AssumptionOverrides::apply($base, ['investmentGrowth' => '3']);

        foreach ($base->assetClasses as $i => $original) {
            $this->assertSame($original->expectedRealReturn->basisPoints, $derived->assetClasses[$i]->expectedRealReturn->basisPoints);
            $this->assertSame($original->volatility->basisPoints, $derived->assetClasses[$i]->volatility->basisPoints);
        }
        $this->assertSame($base->inflationMean->basisPoints, $derived->inflationMean->basisPoints);

        // The mix is where the edit lands, and it costs risk to get there.
        $allocation = AssumptionOverrides::allocation(['investmentGrowth' => '3'], $base);
        $this->assertNotNull($allocation);
        $this->assertEqualsWithDelta(0.03, $allocation->blendedRealReturn($base), 1e-6);
        $this->assertGreaterThan($this->allocation->blendedVolatility($base), $allocation->blendedVolatility($base));
    }

    public function test_a_target_no_mix_can_reach_is_reported_and_clamped(): void
    {
        $base = AssumptionSetLibrary::default();

        // Nothing here earns 6% real: the best asset class returns 4.4%.
        $unreachable = AssumptionOverrides::unreachableGrowthTarget(['investmentGrowth' => '6'], $base);
        $this->assertNotNull($unreachable);
        $this->assertEqualsWithDelta(0.044, $unreachable['max'], 1e-9);

        // A scenario stored before the card can still hold one, so it runs on the closest mix
        // that exists rather than on a return nobody can earn.
        $clamped = AssumptionOverrides::allocation(['investmentGrowth' => '6'], $base);
        $this->assertEqualsWithDelta(0.044, $clamped?->blendedRealReturn($base), 1e-9);

        // A reachable figure has nothing to report.
        $this->assertNull(AssumptionOverrides::unreachableGrowthTarget(['investmentGrowth' => '3'], $base));
    }

    public function test_the_asset_mix_and_its_glidepath_come_off_the_sparse_override_map(): void
    {
        $base = AssumptionSetLibrary::default();

        // Nothing said: null, so the engine's own cautious mix applies AND stays disclosed.
        $this->assertNull(AssumptionOverrides::allocation([], $base));

        $chosen = AssumptionOverrides::allocation(['allocation' => 'growth'], $base);
        $this->assertSame(AllocationProfile::Growth->allocation()->weights, $chosen?->weights);
        $this->assertFalse($chosen->glides());

        $glided = AssumptionOverrides::allocation(
            ['allocation' => 'growth', 'allocationGlideTo' => 'defensive', 'allocationGlideYears' => '20'],
            $base,
        );
        $this->assertTrue($glided?->glides());
        $this->assertSame(20, $glided->glideYears);

        // A target with no length is NOT glided over a length we invented: the engine supplies
        // no default here, and the builder requires the figure (board card 0062).
        $lengthless = AssumptionOverrides::allocation(['allocation' => 'growth', 'allocationGlideTo' => 'defensive'], $base);
        $this->assertFalse($lengthless?->glides());
        $this->assertSame(AllocationProfile::Growth->allocation()->weights, $lengthless->weights);

        // ...and all three keys survive the sparse round trip that persists them.
        $raw = ['allocation' => 'growth', 'allocationGlideTo' => 'defensive', 'allocationGlideYears' => '20'];
        $this->assertSame($raw, AssumptionOverrides::sparse($raw));
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
        );

        $this->assertSame(350, $derived->inflationMean->basisPoints);
        $this->assertSame(200, $derived->houseGrowth->basisPoints);
        $this->assertSame(125, $derived->rentInflation->basisPoints);
        $this->assertSame(50, $derived->salaryGrowth->basisPoints);
        $this->assertSame(280, $derived->investmentIncomeYield->basisPoints);
    }

    public function test_a_property_volatility_edit_reaches_the_set(): void
    {
        // Card 0029. The engine widens the index house volatility for a single home, and the rule
        // is that any figure it supplies for itself must be one the reader can change. A typed
        // figure replaces the derived one outright and stops being reported as assumed.
        $base = AssumptionSetLibrary::default();
        $derived = AssumptionOverrides::apply($base, ['propertyVolatility' => '12']);

        $this->assertSame(1200, $derived->singlePropertyVolatility()?->basisPoints);
        $this->assertFalse($derived->singlePropertyVolatilityIsAssumed());

        // The preset it was derived FROM is untouched and still derives its own figure.
        $this->assertTrue($base->singlePropertyVolatilityIsAssumed());
        $this->assertSame(
            (int) round($base->houseGrowthVolatility->basisPoints * AssumptionSet::SINGLE_PROPERTY_VOLATILITY_MULTIPLE),
            $base->singlePropertyVolatility()?->basisPoints,
        );

        // ...and the edit survives the sparse round trip that persists it in builder_state.
        $this->assertSame(['propertyVolatility' => '12'], AssumptionOverrides::sparse(['propertyVolatility' => '12']));
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
