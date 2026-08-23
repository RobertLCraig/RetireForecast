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
 * (the guidance-only partition). Each figure is the engine's own — the summed
 * {@see YearResult::$totalTax} plus that run's {@see ForecastResult::$iht} — never a
 * re-derivation (the displayed-figure provenance rule), and every saving is exactly the
 * difference of two of those runs (one figure, one home). See {@see lifetimeTax} for why the
 * death tax belongs in the total the optimiser ranks on.
 */
final class WithdrawalStrategyComparison
{
    /**
     * The bounded candidate set the optimiser searches: the named strategies, nothing generated.
     *
     * FLAGGED (board card 0078), because "nothing generated" is a real limit and not just a choice
     * of size: the plan's #6 also describes a "manage taxable income to £X" candidate, tried at a
     * few values of X, and one of those can beat all three of these. It is not here because the plan
     * says to confirm the candidate set with Rob first, and because his decision 1 of 2026-07-01 was
     * a third NAMED strategy and "not a general planner yet". So the search reports the cheapest of
     * the orders the tool can actually run, which is what it says on the page, and 0078 owns
     * widening it. The plan's ceiling is 4 to 6: each candidate is a whole deterministic forecast.
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
     *
     * A deliberate constant rather than "whatever is left", because both templates close with a
     * sentence DESCRIBING what this order does (draw within the personal allowance, the CGT
     * allowance, the tax-free quarter, the Pension Credit exception). That prose is true of
     * fill-the-bands and of nothing else, and no test can see it is wrong — the name guard only
     * catches a hard-coded LABEL. So whoever changes the default must also pick the new alternative
     * and reword that sentence. Written here and in DECISIONS 2026-08-19 rather than only on the
     * card, so it is in front of whoever edits this line.
     */
    public const ALTERNATIVE = DrawdownStrategy::FillBands;

    private function __construct(
        public readonly int $baselineTaxPence,
        public readonly int $fillBandsTaxPence,
        public readonly int $savingPence, // baselineTax - fillBandsTax; positive = fill-the-bands pays less
        public readonly DrawdownStrategy $cheapest,
        public readonly int $cheapestTaxPence,
        public readonly int $optimiserSavingPence, // baselineTax - cheapestTax; never negative
        public readonly bool $includesIht, // the totals carry the death tax as well as the yearly tax
    ) {}

    public static function for(ScenarioForecaster $forecaster, Scenario $scenario): self
    {
        $tax = [];
        $includesIht = false;
        foreach (self::CANDIDATES as $candidate) {
            $run = $forecaster->deterministicUnderStrategy($scenario, $candidate);
            $tax[$candidate->name] = self::lifetimeTax($run);
            // Read off the run rather than the scenario's toggle, so what the page SAYS is in the
            // total is read from the same object the total was summed out of.
            $includesIht = $includesIht || $run->iht !== null;
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
            includesIht: $includesIht,
        );
    }

    /**
     * EVERY pound of tax the plan pays: the year-by-year total ({@see YearResult::$totalTax} —
     * income tax, NI, CGT, dividend and savings tax) PLUS the Inheritance Tax the same run
     * settles at death ({@see ForecastResult::$iht}), which is zero-by-absence when the
     * scenario does not model IHT.
     *
     * The death tax has to be in here, because a draw order changes it two ways and both are
     * large enough to move the ranking:
     *  - An order that pays less income tax ends with MORE wealth, so the estate hands roughly
     *    40% of the "saving" straight back. On income tax alone the headline overstates it.
     *  - For a death before pensions come into the estate (the projector owns that year) a
     *    pension sits OUTSIDE the estate while an ISA sits inside it, so the draw order decides
     *    which pot survives to be taxed at death. That swing can exceed the income-tax gap and
     *    invert which order is cheapest.
     * Both figures are REAL (today's money) and come from the same run, so they add.
     *
     * What makes "least tax" the right thing to rank on at all is that the spend target does NOT
     * change with the draw order: same resources, same spending, so whatever tax does not go to
     * HMRC is left in the plan. Minimising total tax is therefore exactly maximising what is left.
     * FLAGGED (board card 0081): an order that RUNS OUT breaks that premise — it stops drawing, so
     * it stops paying, and it can be reported as the cheapest while funding the least.
     */
    private static function lifetimeTax(ForecastResult $forecast): int
    {
        $total = $forecast->iht?->total->pence ?? 0;
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
            // WHAT is counted in every figure above. Without this the reader cannot tell whether
            // "tax paid across the plan" stops at the last living year or runs to the estate, and
            // the answer moves the totals by tens of thousands (no invisible figures).
            'includesIht' => $this->includesIht,
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
