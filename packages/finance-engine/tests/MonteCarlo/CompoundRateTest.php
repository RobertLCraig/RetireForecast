<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\MonteCarlo;

use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Forecast\PortfolioAllocation;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\MonteCarlo\ReturnModel;
use RetireForecast\FinanceEngine\MonteCarlo\SampledPathDraws;

/**
 * A stated return is a compound rate, so the Monte Carlo's typical path grows at it (board card 0212).
 *
 * The shipped returns are the FCA projection rates, which COBS 13 Annex 2 compounds: the rate a pot
 * grows at over time. Drawing a yearly normal return centred on that rate loses about vol^2/2 a year
 * to compounding, so the median pot grew at about 1.7% where 4.4% was stated. The model now centres
 * each draw on the arithmetic mean that compounds to the stated rate.
 */
final class CompoundRateTest extends TestCase
{
    private const YEARS = 40;

    private const PATHS = 2000;

    private function set(float $equityVol = 23.0, ?float $houseVol = 9.0): AssumptionSet
    {
        return new AssumptionSet(
            name: 'test',
            sourceNote: 'test',
            assetClasses: [
                new AssetClassAssumption('Global equities', Percent::fromPercent(4.4), Percent::fromPercent($equityVol)),
                new AssetClassAssumption('Gilts/bonds', Percent::fromPercent(0.0), Percent::fromPercent(13)),
                new AssetClassAssumption('Cash', Percent::fromPercent(-0.5), Percent::fromPercent(2)),
            ],
            correlationMatrix: [[1.0, 0.30, 0.10], [0.30, 1.0, 0.30], [0.10, 0.30, 1.0]],
            inflationMean: Percent::fromPercent(2.0),
            inflationVolatility: Percent::fromPercent(1.5),
            houseGrowth: Percent::fromPercent(1.0),
            rentInflation: Percent::fromPercent(0.5),
            salaryGrowth: Percent::fromPercent(1.0),
            investmentIncomeYield: Percent::fromPercent(2.0),
            houseGrowthVolatility: $houseVol === null ? null : Percent::fromPercent($houseVol),
        );
    }

    /** @param  list<float>  $values */
    private static function median(array $values): float
    {
        sort($values);

        return $values[intdiv(count($values), 2)];
    }

    /** @param  list<float>  $returns */
    private static function annualised(array $returns): float
    {
        $log = 0.0;
        foreach ($returns as $r) {
            $log += log(1.0 + $r);
        }

        return exp($log / count($returns)) - 1.0;
    }

    public function test_the_monte_carlo_median_grows_at_the_stated_compound_rate(): void
    {
        $model = new ReturnModel($this->set(), new PortfolioAllocation([1.0, 0.0, 0.0]));
        $rng = new Randomizer(new Mt19937(212));

        $growth = [];
        for ($p = 0; $p < self::PATHS; $p++) {
            $growth[] = self::annualised($model->generatePath(self::YEARS, $rng)['investment']);
        }

        $this->assertEqualsWithDelta(0.044, self::median($growth), 0.003);
    }

    public function test_a_zero_volatility_class_draws_its_stated_rate(): void
    {
        $model = new ReturnModel($this->set(equityVol: 0.0), new PortfolioAllocation([1.0, 0.0, 0.0]));
        $path = $model->generatePath(10, new Randomizer(new Mt19937(212)));

        foreach ($path['investment'] as $r) {
            $this->assertEqualsWithDelta(0.044, $r, 1e-12);
        }
    }

    public function test_one_home_median_grows_at_the_stated_rate(): void
    {
        $set = $this->set();
        $model = new ReturnModel($set, new PortfolioAllocation([0.4, 0.6, 0.0]));
        $rng = new Randomizer(new Mt19937(212));

        $growth = [];
        for ($p = 0; $p < self::PATHS; $p++) {
            $draws = new SampledPathDraws($model->generatePath(self::YEARS, $rng), $set, []);
            $returns = [];
            for ($y = 0; $y < self::YEARS; $y++) {
                $returns[] = $draws->propertyGrowthReal($y);
            }
            $growth[] = self::annualised($returns);
        }

        $this->assertEqualsWithDelta(0.010, self::median($growth), 0.003);
    }

    public function test_a_home_with_no_volatility_grows_at_exactly_its_rate(): void
    {
        $set = $this->set(houseVol: null);
        $model = new ReturnModel($set, new PortfolioAllocation([0.4, 0.6, 0.0]));
        $draws = new SampledPathDraws($model->generatePath(5, new Randomizer(new Mt19937(212))), $set, []);

        $this->assertEqualsWithDelta(0.010, $draws->propertyGrowthReal(3), 1e-12);
        $this->assertEqualsWithDelta(0.025, $draws->propertyGrowthReal(3, 0.025), 1e-12);
    }
}
