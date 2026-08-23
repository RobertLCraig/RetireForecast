<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Compliance\Interpretation;
use App\Models\Scenario;
use RetireForecast\FinanceEngine\Forecast\DrawdownStrategy;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;

/**
 * Prices the household's withdrawal (drawdown) sequencing: the total tax paid across the
 * whole plan under the current strategy (tax-efficient: spend non-pension assets first) vs
 * the "fill the bands" strategy, on the SAME household + assumptions + deterministic basis,
 * so the reader can see what re-ordering the draw is worth over a lifetime.
 *
 * It is also the bounded SEARCH OPTIMISER (PLAN-withdrawal-sequencing #6): it runs every
 * candidate order in {@see CANDIDATES} and reports the cheapest. The set is deliberately a
 * fixed handful of named strategies, not a combinatorial search: each candidate is a whole
 * deterministic forecast, so the count is what this costs on a page render. Extending the
 * comparison rather than adding a sibling optimiser keeps it at ONE run per candidate: a
 * separate class would have re-run the two the panel already needs.
 *
 * Neutral by construction: it reports figures and their differences, nothing more. The
 * directive "lean towards X" steer lives only in the walled-off {@see Interpretation}
 * (the guidance-only partition). Each figure is the engine's own summed {@see YearResult::$totalTax},
 * never a re-derivation (the displayed-figure provenance rule), and every saving is exactly the
 * difference of two of those runs (one figure, one home).
 */
final class WithdrawalStrategyComparison
{
    /**
     * The bounded candidate set the optimiser searches: the named strategies, nothing generated.
     *
     * @var list<DrawdownStrategy>
     */
    public const CANDIDATES = [
        DrawdownStrategy::TaxEfficient,
        DrawdownStrategy::PensionAware,
        DrawdownStrategy::FillBands,
    ];

    /**
     * The order a scenario is actually forecast under today, and so the baseline every saving is
     * measured against. Read from the constant {@see ScenarioForecaster::settings()} itself applies
     * rather than restated, so the "your current order" column cannot drift from what the rest of
     * the page shows when the displayed default changes.
     */
    public const CURRENT = ScenarioForecaster::DEFAULT_DRAWDOWN_STRATEGY;

    /**
     * The order the panel puts side by side with the current one — the second tile, and the one
     * {@see $savingPence} prices. It must never BE the current order, or the panel would show the
     * same order twice and report a £0 saving against itself; pinned by
     * ScenarioForecasterTest::test_the_panel_never_compares_the_current_order_against_itself, so
     * changing the displayed default (card 0075) turns the suite red rather than shipping that.
     * The panel's explanatory note describes this order by name, so it is a deliberate constant
     * rather than "whatever is left".
     */
    public const ALTERNATIVE = DrawdownStrategy::FillBands;

    private function __construct(
        public readonly int $baselineTaxPence,
        public readonly int $fillBandsTaxPence,
        public readonly int $savingPence, // baselineTax - fillBandsTax; positive = fill-the-bands pays less
        public readonly DrawdownStrategy $cheapest,
        public readonly int $cheapestTaxPence,
        public readonly int $optimiserSavingPence, // baselineTax - cheapestTax; never negative
    ) {}

    public static function for(ScenarioForecaster $forecaster, Scenario $scenario): self
    {
        $tax = [];
        foreach (self::CANDIDATES as $candidate) {
            $tax[$candidate->name] = self::lifetimeTax($forecaster->deterministicUnderStrategy($scenario, $candidate));
        }

        // The cheapest candidate, starting from the current order so a TIE keeps it: the
        // optimiser only ever reports an order that pays strictly less than what is in place.
        $cheapest = self::CURRENT;
        foreach (self::CANDIDATES as $candidate) {
            if ($tax[$candidate->name] < $tax[$cheapest->name]) {
                $cheapest = $candidate;
            }
        }

        $baseline = $tax[self::CURRENT->name];
        $fillBands = $tax[self::ALTERNATIVE->name];

        return new self(
            baselineTaxPence: $baseline,
            fillBandsTaxPence: $fillBands,
            savingPence: $baseline - $fillBands,
            cheapest: $cheapest,
            cheapestTaxPence: $tax[$cheapest->name],
            optimiserSavingPence: $baseline - $tax[$cheapest->name],
        );
    }

    /** Total tax paid across every year of the projection. */
    private static function lifetimeTax(ForecastResult $forecast): int
    {
        $total = 0;
        foreach ($forecast->years as $year) {
            $total += $year->totalTax->pence;
        }

        return $total;
    }

    /** True when "fill the bands" pays strictly less lifetime tax than the current strategy. */
    public function fillBandsSaves(): bool
    {
        return $this->savingPence > 0;
    }

    /** True when some candidate order pays strictly less lifetime tax than the current one. */
    public function optimiserSaves(): bool
    {
        return $this->optimiserSavingPence > 0;
    }

    /**
     * The neutral figures the "How you draw your money down" panel renders. The screen and the
     * PDF both read THIS, so print cannot drift from screen; each caller adds its own `steer`
     * key from {@see Interpretation} behind the `interpret` gate. Null when the plan pays no tax
     * at all, which is nothing to compare.
     *
     * @return array<string, mixed>|null
     */
    public function panel(): ?array
    {
        if ($this->baselineTaxPence <= 0) {
            return null;
        }

        return [
            'baselineLabel' => self::label(self::CURRENT),
            'baseline' => Money::fromPence($this->baselineTaxPence)->format(),
            // Both tiles and the sentence under them name their order from label(), so neither
            // template can go on naming the order it used to show when a constant changes.
            'alternativeLabel' => self::label(self::ALTERNATIVE),
            'fillBands' => Money::fromPence($this->fillBandsTaxPence)->format(),
            'difference' => Money::fromPence(abs($this->savingPence))->format(),
            'fillBandsSaves' => $this->fillBandsSaves(),
            'differs' => $this->savingPence !== 0,
            // The bounded search across every named draw order (PLAN-withdrawal-sequencing #6).
            'candidateCount' => count(self::CANDIDATES),
            'cheapestLabel' => $this->cheapestLabel(),
            'optimiserSaving' => Money::fromPence($this->optimiserSavingPence)->format(),
            'optimiserSaves' => $this->optimiserSaves(),
        ];
    }

    /**
     * A plain-English NAME for the cheapest order, a label rather than a recommendation; the steer that
     * reads it stays in {@see Interpretation}. Matches the wording the results panel already uses
     * for the two orders it shows side by side.
     */
    public function cheapestLabel(): string
    {
        return self::label($this->cheapest);
    }

    /**
     * THE one home for a draw order's user-facing name, so the baseline tile, the cheapest-order
     * sentence and the steer all name the same order the same way — and the tile keeps naming the
     * right order if {@see ScenarioForecaster::DEFAULT_DRAWDOWN_STRATEGY} changes.
     */
    public static function label(DrawdownStrategy $strategy): string
    {
        return match ($strategy) {
            DrawdownStrategy::TaxEfficient => 'spending your savings first',
            DrawdownStrategy::PensionAware => 'drawing your pension first, up to the basic-rate band',
            DrawdownStrategy::FillBands => 'filling your tax-free allowances first',
        };
    }
}
