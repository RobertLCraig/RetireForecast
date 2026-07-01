<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Forecast\ResultPresenter;
use App\Forecast\WithdrawalStrategyComparison;
use App\Models\Result;
use Illuminate\Support\Collection;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Money\Money;

/**
 * The single, walled-off home for advice-style "what this suggests" readouts.
 *
 * This is deliberately the ONLY place in the app permitted to phrase a directive
 * recommendation. It is reachable only when the per-user `interpret` Gate allows
 * (admin-granted, off by default — never self-serve), and the directive sentences are
 * produced here from the computed numbers, not baked into the result templates. That
 * separation is what lets the build-time banned-phrasing test stay a clean partition
 * (directive wording here, neutral everywhere else). See DECISIONS 2026-06-25.
 *
 * The neutral {@see ResultPresenter} remains the public default; nothing here changes
 * the figures, it only interprets them.
 */
final class Interpretation
{
    /**
     * Directive readouts ranking the housing options by how well they fund the user's
     * spending. Returns plain sentences for the gated partial to list.
     *
     * @param  Collection<string, Result>  $resultsByVariant  keyed by variant value
     * @return list<string>
     */
    public static function readouts(Collection $resultsByVariant): array
    {
        $scored = [];
        foreach ($resultsByVariant as $key => $result) {
            $r = $result->simulationResult();
            $scored[$key] = [
                'label' => ResultPresenter::variantLabel($result->variant),
                'fullSpend' => $r->successProbabilityFullSpend,
                'depletion' => $r->depletionRate,
                'terminal' => $r->terminalWealthPercentiles['p50']->pence,
            ];
        }

        if ($scored === []) {
            return [];
        }

        // Best = most futures meeting full spend, then most typical wealth left.
        uasort($scored, fn (array $a, array $b): int => [$b['fullSpend'], $b['terminal']] <=> [$a['fullSpend'], $a['terminal']]);
        $best = reset($scored);
        $worst = end($scored);

        // Use the presenter's single percentage formatter, so an interpreted figure is
        // formatted identically to the same figure on the neutral panel (one figure, one
        // home — the displayed-figure provenance rule).
        $bestLabel = $best['label'];
        $worstLabel = $worst['label'];
        $bestFull = ResultPresenter::formatPercent($best['fullSpend']);
        $worstFull = ResultPresenter::formatPercent($worst['fullSpend']);
        $bestDeplete = ResultPresenter::formatPercent($best['depletion']);
        $worstDeplete = ResultPresenter::formatPercent($worst['depletion']);

        if (count($scored) < 2) {
            return [
                "On these assumptions, {$bestLabel} funds your full spending in {$bestFull} of simulated futures and runs out of money in {$bestDeplete} of them.",
            ];
        }

        return [
            "Across these simulations, {$bestLabel} is the best option for keeping your full spending funded for life: {$bestFull} of futures meet it, against {$worstFull} for {$worstLabel}.",
            "If making the money last is your priority, you should lean towards {$bestLabel} — it runs out of money in {$bestDeplete} of futures, compared with {$worstDeplete} for {$worstLabel}.",
            'Remember these are still only consequences of your inputs under one set of assumptions; revisit them if your spending, returns or longevity differ.',
        ];
    }

    /**
     * A directive "why" narrative for the Compare view: ranks the compared plans (a base and
     * its what-ifs, e.g. the buy / stay / rent strategies) by their central deterministic
     * outcome and says which to lean towards and why. Used for the buy-vs-rent comparison so
     * the reader gets a plain-English recommendation, not just a table. Like the rest of this
     * class it is reachable only behind the `interpret` ability; the directive wording lives
     * here (the exempt layer), never in the neutral templates.
     *
     * @param  list<array{name: string, forecast: ForecastResult}>  $plans
     * @return list<string>
     */
    public static function compareNarrative(array $plans): array
    {
        $plans = array_values(array_filter($plans, static fn (array $p): bool => ($p['forecast'] ?? null) instanceof ForecastResult));
        if (count($plans) < 2) {
            return [];
        }

        // Rank: the money lasting beats not lasting; then more spendable wealth left.
        usort($plans, static fn (array $a, array $b): int => [self::lasts($b['forecast']), $b['forecast']->terminalUsableWealth->pence]
            <=> [self::lasts($a['forecast']), $a['forecast']->terminalUsableWealth->pence]);

        $best = $plans[0];
        $worst = $plans[count($plans) - 1];

        $lines = [
            "On these figures, {$best['name']} is the strongest plan — ".self::outcome($best['forecast']).'. It is the one to lean towards.',
        ];
        if ($worst['name'] !== $best['name']) {
            $lines[] = "{$worst['name']} is the weakest — ".self::outcome($worst['forecast']).'.';
        }
        $lines[] = 'This is one central projection on your current assumptions; run the full simulation for the range of futures, and revisit it if your spending, returns or longevity differ.';

        return $lines;
    }

    /**
     * A directive steer on withdrawal sequencing: which draw order pays less lifetime tax,
     * from the neutral {@see WithdrawalStrategyComparison}. Reachable only behind the
     * `interpret` ability; the figures themselves stay on the neutral results panel.
     *
     * @return list<string>
     */
    public static function withdrawalSequencingNarrative(WithdrawalStrategyComparison $comparison): array
    {
        if ($comparison->savingPence === 0) {
            return ['On these figures the order you draw your money makes no difference to the tax you pay, so there is nothing to choose between them here.'];
        }

        if ($comparison->fillBandsSaves()) {
            $amount = Money::fromPence($comparison->savingPence)->format();

            return [
                "Filling your tax-free allowances first would pay {$amount} less tax across your plan; on these figures it is the order to lean towards for tax.",
                'This is one central projection on your current assumptions; revisit it if your spending, income or returns differ.',
            ];
        }

        $amount = Money::fromPence(-$comparison->savingPence)->format();

        return [
            "Your current order, spending your savings first, already pays {$amount} less tax than filling the bands, so on these figures it is the one to lean towards.",
            'This is one central projection on your current assumptions; revisit it if your spending, income or returns differ.',
        ];
    }

    private static function lasts(ForecastResult $f): int
    {
        return $f->depletionCalendarYear === null ? 1 : 0;
    }

    /** The plain-English outcome of one plan's central projection: does the money last, and is spending funded. */
    private static function outcome(ForecastResult $f): string
    {
        $spend = match (true) {
            $f->fullSpendAlwaysMet => 'your full spending is funded every year',
            $f->essentialsAlwaysMet => 'essentials are funded every year, though not always the full spend',
            default => 'even essentials fall short in some years',
        };

        $lasts = $f->depletionCalendarYear === null
            ? "the money lasts to {$f->finalCalendarYear} with ".$f->terminalUsableWealth->format().' of spendable wealth left'
            : "the money runs short in {$f->depletionCalendarYear}";

        return "{$lasts}, and {$spend}";
    }
}
