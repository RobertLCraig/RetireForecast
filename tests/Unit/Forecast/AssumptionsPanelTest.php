<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\ResultPresenter;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Forecast\PortfolioAllocation;
use RetireForecast\FinanceEngine\Housing\SellingCostComponent;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * The assumptions panel (show-your-working): it surfaces the very figures the forecast runs
 * on, so a headline number traces to its basis. The trust-critical properties: the blended
 * investment return is the engine's own single-source figure (no re-derivation that could
 * drift), and a REAL figure is never labelled as NOMINAL or vice versa — confusing the two
 * is exactly how a "show your working" panel would mislead.
 */
final class AssumptionsPanelTest extends TestCase
{
    /** @return array<string, mixed> */
    private function panel(?HousingAction $action = null): array
    {
        return ResultPresenter::assumptionsPanel(
            AssumptionSetLibrary::default(),
            $action ?? new HousingAction(salePrice: Money::zero()),
            PortfolioAllocation::cautious40_60(),
        );
    }

    /** @param  list<array{label: string, value: string, note: string}>  $rows */
    private function value(array $rows, string $labelFragment): string
    {
        foreach ($rows as $row) {
            if (str_contains($row['label'], $labelFragment)) {
                return $row['value'];
            }
        }

        return '';
    }

    public function test_the_blended_return_matches_the_engine_single_source(): void
    {
        $set = AssumptionSetLibrary::default();

        // Cautious 40/60: 0.40 × 4.4% + 0.60 × 0.0% = 1.76% real.
        $this->assertSame('1.76%', $this->value($this->panel()['economic'], 'Investment growth'));

        // It tracks the engine's allocation-weighted blendedRealReturn, not a fixed string:
        // an all-equities mix (4.4% real) shows 4.4%, proving the panel reads the single source.
        $allEquities = ResultPresenter::assumptionsPanel(
            $set,
            new HousingAction(salePrice: Money::zero()),
            new PortfolioAllocation([1.0, 0.0, 0.0]),
        );
        $this->assertSame('4.4%', $this->value($allEquities['economic'], 'Investment growth'));
    }

    public function test_real_and_nominal_figures_are_labelled_so_they_cannot_be_confused(): void
    {
        $economic = $this->panel()['economic'];

        // Returns/growth are real (above inflation); the income yield is nominal; inflation is CPI.
        $labels = array_column($economic, 'label');
        $this->assertContains('Investment growth (blended, real)', $labels);
        $this->assertContains('House price growth (real)', $labels);
        $this->assertContains('Rent growth (real)', $labels);
        $this->assertContains('Salary growth (real)', $labels);
        $this->assertContains('Investment income yield (nominal)', $labels);
        $this->assertContains('Inflation (CPI)', $labels);

        // The default set's figures, surfaced exactly.
        $this->assertSame('2%', $this->value($economic, 'Inflation'));
        $this->assertSame('1%', $this->value($economic, 'House price growth'));
        $this->assertSame('0.5%', $this->value($economic, 'Rent growth'));
        $this->assertSame('2%', $this->value($economic, 'Investment income yield'));
    }

    public function test_the_mix_describes_what_the_blended_return_is_weighted_from(): void
    {
        // The mix is built from the weights + asset-class names, so it can never drift from
        // the blended figure it explains.
        $this->assertSame('40% global equities / 60% gilts/bonds', $this->panel()['mix']);
    }

    public function test_the_source_note_and_set_name_travel_with_the_figures(): void
    {
        // No magic numbers: the panel names the set and carries its sourcing.
        $panel = $this->panel();
        $this->assertStringContainsString('FCA default', $panel['setName']);
        $this->assertStringContainsString('FCA COBS', $panel['sourceNote']);
    }

    public function test_housing_inputs_surface_only_what_is_set(): void
    {
        // No housing inputs beyond the (defaulted) selling rate when nothing else is configured.
        $bare = $this->panel(new HousingAction(salePrice: Money::fromPounds(300_000)));
        $bareLabels = array_column($bare['housing'], 'label');
        $this->assertContains('Selling costs', $bareLabels);
        $this->assertNotContains('Cheaper home to buy', $bareLabels);
        $this->assertNotContains('Rent (if renting)', $bareLabels);

        // A full housing action surfaces the buy price, rent and moving costs too, and each
        // selling-cost component on its own basis (% of sale or flat fee).
        $full = $this->panel(new HousingAction(
            salePrice: Money::fromPounds(300_000),
            buyPrice: Money::fromPounds(200_000),
            annualRent: Money::fromPounds(14_000),
            movingCosts: Money::fromPounds(2_000),
            sellingCosts: [new SellingCostComponent('Estate agent', Percent::fromPercent(1.5))],
        ));
        $fullLabels = array_column($full['housing'], 'label');
        $this->assertContains('Cheaper home to buy', $fullLabels);
        $this->assertContains('Rent if you sell & rent', $fullLabels);
        $this->assertContains('Moving costs', $fullLabels);
        $this->assertContains('Selling cost — Estate agent', $fullLabels);
        // 1.5% of £300,000 = £4,500, shown with its basis.
        $this->assertStringContainsString('1.5% of sale', $this->value($full['housing'], 'Selling cost — Estate agent'));
        $this->assertStringContainsString(Money::fromPounds(4_500)->format(), $this->value($full['housing'], 'Selling cost — Estate agent'));
    }

    public function test_with_no_overrides_the_panel_is_not_customised_and_no_row_is_edited(): void
    {
        $panel = $this->panel();

        $this->assertFalse($panel['customised']);
        foreach ($panel['economic'] as $row) {
            $this->assertFalse($row['edited']);
        }
    }

    public function test_overrides_mark_the_set_customised_and_flag_only_the_changed_rows(): void
    {
        $panel = ResultPresenter::assumptionsPanel(
            AssumptionSetLibrary::default(),
            new HousingAction(salePrice: Money::zero()),
            PortfolioAllocation::cautious40_60(),
            ['investmentGrowth' => '3', 'inflation' => ''], // one filled, one blank
        );

        $this->assertTrue($panel['customised']);

        $edited = [];
        foreach ($panel['economic'] as $row) {
            if ($row['edited']) {
                $edited[] = $row['key'];
            }
        }

        // Only the figure the user filled is the user's; a blank stays the preset's.
        $this->assertSame(['investmentGrowth'], $edited);
    }
}
