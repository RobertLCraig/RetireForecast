<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Forecast\ResultPresenter;
use App\Models\Result;
use Illuminate\Support\Collection;

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
}
