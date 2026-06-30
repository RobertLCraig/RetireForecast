<?php

declare(strict_types=1);

namespace Tests\Unit\Compliance;

use App\Compliance\Interpretation;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Money\Money;

/**
 * The advice-style "why" narrative for the Compare view (the buy-vs-rent recommendation).
 * It must rank the plans by their central outcome — money lasting first, then spendable
 * wealth left — and say which to lean towards. This wording is deliberately directive and
 * lives in the walled-off {@see Interpretation} layer (exempt from the banned-phrasing lint);
 * it is reached only in personal-use / granted mode.
 */
final class CompareNarrativeTest extends TestCase
{
    private function forecastResult(bool $lasts, bool $fullSpend, ?int $depletion, int $usablePounds): ForecastResult
    {
        return new ForecastResult(
            years: [],
            essentialsAlwaysMet: true,
            fullSpendAlwaysMet: $fullSpend,
            depletionCalendarYear: $depletion,
            terminalTotalWealth: Money::fromPounds($usablePounds + 100_000),
            terminalUsableWealth: Money::fromPounds($usablePounds),
            finalCalendarYear: 2074,
            deathCalendarYears: [],
        );
    }

    public function test_it_recommends_the_plan_whose_money_lasts(): void
    {
        $lines = Interpretation::compareNarrative([
            ['name' => 'Sell & rent', 'forecast' => $this->forecastResult(lasts: false, fullSpend: false, depletion: 2061, usablePounds: 0)],
            ['name' => 'Stay put', 'forecast' => $this->forecastResult(lasts: true, fullSpend: true, depletion: null, usablePounds: 300_000)],
        ]);

        $this->assertStringContainsString('Stay put is the strongest plan', $lines[0]);
        $this->assertStringContainsString('lean towards', $lines[0]);
        $this->assertStringContainsString('Sell & rent is the weakest', $lines[1]);
        $this->assertStringContainsString('runs short in 2061', $lines[1]);
    }

    public function test_when_both_last_it_prefers_the_one_leaving_more_spendable_wealth(): void
    {
        $lines = Interpretation::compareNarrative([
            ['name' => 'Buy cheaper', 'forecast' => $this->forecastResult(lasts: true, fullSpend: true, depletion: null, usablePounds: 250_000)],
            ['name' => 'Stay put', 'forecast' => $this->forecastResult(lasts: true, fullSpend: true, depletion: null, usablePounds: 400_000)],
        ]);

        $this->assertStringContainsString('Stay put is the strongest plan', $lines[0]);
    }

    public function test_a_single_plan_has_nothing_to_compare(): void
    {
        $this->assertSame([], Interpretation::compareNarrative([
            ['name' => 'Stay put', 'forecast' => $this->forecastResult(lasts: true, fullSpend: true, depletion: null, usablePounds: 300_000)],
        ]));
    }
}
