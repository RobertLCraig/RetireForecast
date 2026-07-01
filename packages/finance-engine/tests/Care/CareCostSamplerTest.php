<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Care;

use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RetireForecast\FinanceEngine\Care\CareAssumptions;
use RetireForecast\FinanceEngine\Care\CareCostSampler;
use RetireForecast\FinanceEngine\Money\Money;

final class CareCostSamplerTest extends TestCase
{
    private function assumptions(float $probability, float $probabilityNursing = 0.0): CareAssumptions
    {
        return new CareAssumptions(
            probabilityOfCare: $probability,
            meanDurationYears: 2.5,
            maxDurationYears: 8,
            probabilityNursing: $probabilityNursing,
            residentialWeekly: Money::fromPounds(1_300),
            nursingWeekly: Money::fromPounds(1_600),
        );
    }

    /** @return list<array{id: string, currentAge: int, deathAge: int}> */
    private function people(): array
    {
        return [
            ['id' => 'p1', 'currentAge' => 68, 'deathAge' => 88],
            ['id' => 'p2', 'currentAge' => 66, 'deathAge' => 90],
        ];
    }

    public function test_certain_care_gives_everyone_an_end_of_life_spell(): void
    {
        $episodes = (new CareCostSampler($this->assumptions(1.0)))
            ->sampleHousehold($this->people(), new Randomizer(new Mt19937(42)));

        $this->assertCount(2, $episodes);
        foreach ($episodes as $id => $episode) {
            // The spell ends at the age at death and is a whole number of years, 1..8 long.
            $deathAge = $id === 'p1' ? 88 : 90;
            $this->assertSame($deathAge, $episode->toAge);
            $length = $episode->toAge - $episode->fromAge + 1;
            $this->assertGreaterThanOrEqual(1, $length);
            $this->assertLessThanOrEqual(8, $length);
            // Residential (nursing probability 0) at £1,300/wk × 52 = £67,600 a year.
            $this->assertSame(Money::fromPounds(67_600)->pence, $episode->annualCost->pence);
        }
    }

    public function test_zero_probability_gives_no_care(): void
    {
        $episodes = (new CareCostSampler($this->assumptions(0.0)))
            ->sampleHousehold($this->people(), new Randomizer(new Mt19937(1)));

        $this->assertSame([], $episodes);
    }

    public function test_nursing_certainty_charges_the_nursing_rate(): void
    {
        $episodes = (new CareCostSampler($this->assumptions(1.0, probabilityNursing: 1.0)))
            ->sampleHousehold([['id' => 'p1', 'currentAge' => 68, 'deathAge' => 88]], new Randomizer(new Mt19937(7)));

        // Nursing at £1,600/wk × 52 = £83,200 a year.
        $this->assertSame(Money::fromPounds(83_200)->pence, $episodes['p1']->annualCost->pence);
    }

    public function test_annual_cost_only_applies_within_the_spell(): void
    {
        $episodes = (new CareCostSampler($this->assumptions(1.0)))
            ->sampleHousehold([['id' => 'p1', 'currentAge' => 68, 'deathAge' => 88]], new Randomizer(new Mt19937(3)));

        $episode = $episodes['p1'];
        $this->assertSame(0, $episode->annualCostAt($episode->fromAge - 1), 'before the spell: no cost');
        $this->assertGreaterThan(0, $episode->annualCostAt($episode->toAge), 'at death age: in care');
        $this->assertSame(0, $episode->annualCostAt($episode->toAge + 1), 'after death: no cost');
    }

    public function test_it_is_reproducible_for_a_fixed_seed(): void
    {
        $a = (new CareCostSampler($this->assumptions(0.5)))->sampleHousehold($this->people(), new Randomizer(new Mt19937(99)));
        $b = (new CareCostSampler($this->assumptions(0.5)))->sampleHousehold($this->people(), new Randomizer(new Mt19937(99)));

        $this->assertSame(array_keys($a), array_keys($b));
        foreach ($a as $id => $episode) {
            $this->assertSame($episode->fromAge, $b[$id]->fromAge);
            $this->assertSame($episode->annualCost->pence, $b[$id]->annualCost->pence);
        }
    }
}
