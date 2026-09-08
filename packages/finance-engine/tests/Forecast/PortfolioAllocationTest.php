<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Forecast\AllocationProfile;
use RetireForecast\FinanceEngine\Forecast\DeterministicPathDraws;
use RetireForecast\FinanceEngine\Forecast\HistoricalSequenceDraws;
use RetireForecast\FinanceEngine\Forecast\PortfolioAllocation;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\MonteCarlo\ReturnModel;

/**
 * Board card 0062. The asset mix is the largest single determinant of the answer, and it
 * used to be a hardcoded 40/60 that nothing could move, with an "investment growth" edit
 * bolted on that raised the RETURN of every asset class and left the volatilities and the
 * correlations exactly where they were. That is a free lunch inside a Monte Carlo whose
 * whole job is to price risk: a reader who raised growth because they hold shares got a
 * share-like return at a cautious portfolio's spread.
 *
 * The three properties this pins:
 *  - a named mix blends to its own return, and a mix with more shares in it is riskier
 *    (criterion 1);
 *  - asking for a higher return RE-WEIGHTS the mix, so the portfolio volatility rises with
 *    it, and a return no mix can reach is refused rather than manufactured (criterion 2);
 *  - a glidepath de-risks over the projection, and the projector's draws see the year's
 *    own mix rather than year 0's for ever (criterion 3).
 */
final class PortfolioAllocationTest extends TestCase
{
    public function test_a_named_profile_carries_its_own_return_and_its_own_risk(): void
    {
        $set = AssumptionSetLibrary::default();

        $cautious = AllocationProfile::Cautious->allocation();
        $growth = AllocationProfile::Growth->allocation();

        // The shipped default is unchanged: 40% equities / 60% bonds is still what a reader
        // who says nothing gets.
        $this->assertSame([0.40, 0.60, 0.0], PortfolioAllocation::cautious40_60()->weights);
        $this->assertSame(PortfolioAllocation::cautious40_60()->weights, $cautious->weights);

        // More shares: more return AND more risk. Both halves matter — the point of the card
        // is that the two can never again move apart.
        $this->assertGreaterThan($cautious->blendedRealReturn($set), $growth->blendedRealReturn($set));
        $this->assertGreaterThan($cautious->blendedVolatility($set), $growth->blendedVolatility($set));
    }

    public function test_asking_for_a_higher_return_re_weights_the_mix_and_raises_the_volatility(): void
    {
        $set = AssumptionSetLibrary::default();
        $from = PortfolioAllocation::cautious40_60();

        // 1.76% is what 40/60 blends to on this set. Ask for 3%.
        $this->assertEqualsWithDelta(0.0176, $from->blendedRealReturn($set), 1e-9);
        $reweighted = PortfolioAllocation::forBlendedRealReturn($set, 0.03, $from);

        $this->assertNotNull($reweighted);
        $this->assertEqualsWithDelta(0.03, $reweighted->blendedRealReturn($set), 1e-6);

        // The risk moved with the return: this is the defect the card was raised for.
        $this->assertGreaterThan($from->blendedVolatility($set), $reweighted->blendedVolatility($set));

        // ...and it moved by re-weighting, not by inventing return: the asset classes the mix
        // is built from are the preset's own, untouched.
        $this->assertGreaterThan($from->weights[0], $reweighted->weights[0]);
        $this->assertEqualsWithDelta(1.0, array_sum($reweighted->weights), 1e-9);
    }

    public function test_a_return_no_mix_can_reach_is_refused(): void
    {
        $set = AssumptionSetLibrary::default();

        // Equities are the highest-returning class in the set, so nothing above an all-equity
        // blend is reachable at any risk. It is refused rather than manufactured.
        [$min, $max] = PortfolioAllocation::reachableRealReturnRange($set, PortfolioAllocation::cautious40_60());
        $this->assertEqualsWithDelta(0.044, $max, 1e-9);
        $this->assertEqualsWithDelta(0.0, $min, 1e-9);

        $this->assertNull(PortfolioAllocation::forBlendedRealReturn($set, 0.06, PortfolioAllocation::cautious40_60()));
        $this->assertNull(PortfolioAllocation::forBlendedRealReturn($set, -0.01, PortfolioAllocation::cautious40_60()));
    }

    public function test_a_glidepath_de_risks_the_mix_over_the_projection(): void
    {
        $set = AssumptionSetLibrary::default();

        $glide = AllocationProfile::Growth->allocation()->glidingTo(AllocationProfile::Defensive->allocation(), 10);

        // Year 0 is the starting mix, the end year is the target, and half way is half way.
        $this->assertSame(AllocationProfile::Growth->allocation()->weights, $glide->at(0)->weights);
        $this->assertEqualsWithDelta(AllocationProfile::Defensive->allocation()->weights[0], $glide->at(10)->weights[0], 1e-9);
        $this->assertEqualsWithDelta(0.50, $glide->at(5)->weights[0], 1e-9);

        // Past the end it stays at the target rather than gliding on into a mix nobody chose.
        $this->assertEqualsWithDelta($glide->at(10)->weights[0], $glide->at(40)->weights[0], 1e-9);

        // De-risking means less risk, and (on these figures) less return: the trade-off is real
        // and the reader is not being handed one without the other.
        $this->assertLessThan($glide->at(0)->blendedVolatility($set), $glide->at(10)->blendedVolatility($set));
        $this->assertLessThan($glide->at(0)->blendedRealReturn($set), $glide->at(10)->blendedRealReturn($set));
    }

    public function test_the_deterministic_path_grows_the_pot_at_the_years_own_mix(): void
    {
        $set = AssumptionSetLibrary::default();
        $glide = AllocationProfile::Growth->allocation()->glidingTo(AllocationProfile::Defensive->allocation(), 10);

        $draws = new DeterministicPathDraws($set, $glide, ['p1' => 90]);

        // The projector asks per year, so a glidepath that the draws ignored would show up as a
        // flat return for ever — which is what a fixed allocation looks like.
        $this->assertGreaterThan($draws->investmentRealReturn(10), $draws->investmentRealReturn(0));
        $this->assertEqualsWithDelta(
            AllocationProfile::Defensive->allocation()->blendedRealReturn($set),
            $draws->investmentRealReturn(20),
            1e-9,
        );

        // A fixed allocation is unchanged: every year is year 0's blend, so no stored run moves.
        $fixed = new DeterministicPathDraws($set, PortfolioAllocation::cautious40_60(), ['p1' => 90]);
        $this->assertSame($fixed->investmentRealReturn(0), $fixed->investmentRealReturn(30));
    }

    public function test_the_monte_carlo_samples_the_years_own_mix(): void
    {
        // A set with no volatility anywhere, so a sampled path IS the mix's expected return and
        // the glidepath can be read off it exactly. (With the real volatilities the same weights
        // are used; this removes the noise, not the arithmetic.)
        $set = $this->certainSet();
        $glide = AllocationProfile::Growth->allocation()->glidingTo(AllocationProfile::Defensive->allocation(), 10);

        $path = (new ReturnModel($set, $glide))->generatePath(20, new Randomizer(new Xoshiro256StarStar(7)));

        $this->assertEqualsWithDelta(AllocationProfile::Growth->allocation()->blendedRealReturn($set), $path['investment'][0], 1e-9);
        $this->assertEqualsWithDelta(AllocationProfile::Defensive->allocation()->blendedRealReturn($set), $path['investment'][10], 1e-9);
        $this->assertEqualsWithDelta($path['investment'][10], $path['investment'][19], 1e-9);
        $this->assertLessThan($path['investment'][0], $path['investment'][5]);
    }

    public function test_the_historical_backtest_replays_the_years_own_mix(): void
    {
        $set = AssumptionSetLibrary::default();
        $glide = AllocationProfile::Growth->allocation()->glidingTo(AllocationProfile::Defensive->allocation(), 10);

        $glided = new HistoricalSequenceDraws($set, $glide, 1970, ['p1' => 90]);
        // The same historical year, replayed against the mix the glidepath has reached by then.
        $atTen = new HistoricalSequenceDraws($set, $glide->at(10), 1980, ['p1' => 90]);

        $this->assertEqualsWithDelta($atTen->investmentRealReturn(0), $glided->investmentRealReturn(10), 1e-12);
        $this->assertNotEqualsWithDelta($glided->investmentRealReturn(0), $glided->investmentRealReturn(10), 1e-12);
    }

    /** The default set with every asset volatility set to zero, so a sampled path is its own mean. */
    private function certainSet(): AssumptionSet
    {
        $base = AssumptionSetLibrary::default();

        return $base->withAssetClasses(array_map(
            static fn (AssetClassAssumption $a): AssetClassAssumption => new AssetClassAssumption(
                $a->name,
                $a->expectedRealReturn,
                Percent::zero(),
            ),
            $base->assetClasses,
        ));
    }
}
