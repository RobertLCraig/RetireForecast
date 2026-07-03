<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Forecast\LumpSumTaxShock;
use App\Forecast\ResultPresenter;
use App\Forecast\ScenarioForecaster;
use App\Models\Scenario;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;

/**
 * The bounded, labelled snapshot of a scenario's forecast that the assistant reasons over. It is
 * the SINGLE source for two things at once: the context shown to the model AND the grounding
 * allow-list its answer is checked against ({@see promptBlock()}) — so the model can only be
 * given, and can only legitimately state, exactly these engine figures (guardrail G1).
 *
 * A big part of the point is to let the reader interrogate figures the UI does NOT spell out —
 * "how much are my essentials in five years?", "what's my tax in 2035?" — so the snapshot carries
 * the headline summary, the full year-by-year cashflow ladder (the same reconciled rows the ladder
 * panel shows), the Monte Carlo probabilities and ranges when a full simulation has been run (chance
 * the money lasts, chance of running out, spread of terminal wealth, longevity, care risk), and the
 * pension lump-sum tax shock when one is planned (the flagship figure: 25% tax-free, marginal tax, the
 * Month-1 emergency over-deduction + reclaim), and the home-sale waterfall for a sell strategy (what you
 * pocket after the mortgage, costs and CGT, then invest/rent or buy cheaper). The central (deterministic)
 * figures answer "what happens"; the Monte Carlo ones answer "how likely / what's the range". Every figure
 * is inline-labelled, so the model states the right number for the right thing (right-number-wrong-meaning,
 * LA-8).
 */
final class ScenarioContext implements AssistantContext
{
    /**
     * @param  list<AssistantFact>  $facts
     */
    private function __construct(
        public readonly string $title,
        public readonly array $facts,
        public readonly string $ladder = '',
        public readonly bool $hasMonteCarlo = false,
    ) {}

    public function includesMonteCarlo(): bool
    {
        return $this->hasMonteCarlo;
    }

    public function systemIntro(): string
    {
        return 'You help the reader understand THEIR OWN forecast, shown under CONTEXT below.';
    }

    /**
     * Build the context by running the scenario's central deterministic forecast, optionally with
     * a completed Monte Carlo run's aggregate ({@see SimulationResult}) for the probability/range
     * figures. Null simulation = no run yet; the deterministic view still stands.
     */
    public static function for(Scenario $scenario, ScenarioForecaster $forecaster, ?SimulationResult $simulation = null, ?array $taxShock = null, ?array $saleExplainer = null): self
    {
        return self::fromForecast(
            $scenario->name,
            ResultPresenter::strategyLabel($scenario->variant->value),
            $forecaster->deterministic($scenario),
            $simulation,
            $taxShock,
            $saleExplainer,
        );
    }

    /**
     * Build the facts from a forecast result (+ optional Monte Carlo aggregate + optional pension
     * lump-sum tax shock + optional home-sale waterfall). Pure — no container, no I/O — so it is
     * unit-testable from hand-built inputs. $taxShock is the {@see LumpSumTaxShock} array and
     * $saleExplainer is the {@see ResultPresenter::saleExplainer()} array (both already-formatted),
     * each null when it does not apply (no lump sum / not a sell strategy).
     *
     * @param  array<string, mixed>|null  $taxShock
     * @param  array<string, mixed>|null  $saleExplainer
     */
    public static function fromForecast(string $title, string $strategyLabel, ForecastResult $forecast, ?SimulationResult $simulation = null, ?array $taxShock = null, ?array $saleExplainer = null): self
    {
        $facts = [
            new AssistantFact('Plan', $title),
            new AssistantFact('Housing strategy', $strategyLabel),
        ];

        $facts[] = $forecast->depletionCalendarYear === null
            ? new AssistantFact('Does the money last', "Yes — it lasts to {$forecast->finalCalendarYear}")
            : new AssistantFact('Does the money last', "No — it runs short in {$forecast->depletionCalendarYear}");

        $facts[] = new AssistantFact('Final year of the plan', (string) $forecast->finalCalendarYear);
        $facts[] = new AssistantFact('Spendable wealth left at the end (excludes the home)', $forecast->terminalUsableWealth->format());
        $facts[] = new AssistantFact('Total wealth left at the end (includes the home)', $forecast->terminalTotalWealth->format());
        $facts[] = new AssistantFact('Essential spending funded every year', $forecast->essentialsAlwaysMet ? 'Yes' : 'No');
        $facts[] = new AssistantFact('Full (essential + discretionary) spending funded every year', $forecast->fullSpendAlwaysMet ? 'Yes' : 'No');

        foreach ($forecast->deathCalendarYears as $personId => $year) {
            $facts[] = new AssistantFact("Modelled year of death (person {$personId})", (string) $year);
        }

        $care = $forecast->careCostReal();
        if (! $care->isZero()) {
            $facts[] = new AssistantFact('Modelled late-life care cost on this path (today\'s money)', $care->format());
        }

        if ($simulation !== null) {
            $facts = [...$facts, ...self::monteCarloFacts($simulation)];
        } else {
            $facts[] = new AssistantFact(
                'Monte Carlo probabilities',
                'Not available yet — no completed simulation run. The figures above are one central projection; run the full simulation on the results page to see the chance the money lasts and the range of outcomes.',
            );
        }

        if ($taxShock !== null) {
            $facts = [...$facts, ...self::taxShockFacts($taxShock)];
        }

        if ($saleExplainer !== null) {
            $facts = [...$facts, ...self::saleFacts($saleExplainer)];
        }

        return new self($title, $facts, self::renderLadder($forecast), $simulation !== null);
    }

    /**
     * The home-sale waterfall as facts — for a sell strategy, what the household actually pockets:
     * sale price less the mortgage, selling costs and any CGT = net proceeds, then either invested
     * (sell & rent) or put toward a cheaper home (with the surplus, or the shortfall when the buy
     * costs more than the proceeds cover). Reuses the already-formatted
     * {@see ResultPresenter::saleExplainer()} array, so the figures match the sale-waterfall panel.
     *
     * @param  array<string, mixed>  $s
     * @return list<AssistantFact>
     */
    private static function saleFacts(array $s): array
    {
        $p = $s['proceeds'];
        $facts = [new AssistantFact('Home sale — sale price', $p['salePrice'])];

        if ($p['hasMortgage']) {
            $facts[] = new AssistantFact('Home sale — mortgage cleared from the sale', $p['mortgage']);
        }
        $facts[] = new AssistantFact('Home sale — selling costs', $p['sellingCosts']);
        if ($p['cgtCharged']) {
            $facts[] = new AssistantFact('Home sale — capital gains tax on the sale', $p['cgt']);
        }
        $facts[] = new AssistantFact('Home sale — net proceeds (what you actually pocket)', $p['netProceeds']);

        if ($s['buy'] !== null) {
            $b = $s['buy'];
            $facts[] = new AssistantFact('Home sale — buying a cheaper home: purchase price', $b['buyPrice']);
            $facts[] = new AssistantFact('Home sale — buying a cheaper home: stamp duty', $b['sdlt']);
            $facts[] = new AssistantFact('Home sale — buying a cheaper home: moving costs', $b['movingCosts']);
            if ($b['coversPurchase']) {
                $facts[] = new AssistantFact('Home sale — surplus left over to invest after buying', $b['surplus']);
            } elseif ($b['shortfall'] !== null) {
                $facts[] = new AssistantFact('Home sale — shortfall: the purchase costs this much more than the proceeds cover', $b['shortfall']);
            }
        } elseif (($s['rent']['annualRent'] ?? null) !== null) {
            $facts[] = new AssistantFact('Home sale — proceeds invested (sell & rent)', $s['rent']['invested']);
            $facts[] = new AssistantFact('Home sale — annual rent then paid', $s['rent']['annualRent']);
        }

        return $facts;
    }

    /**
     * The pension lump-sum tax shock as facts — the tool's flagship figure: the 25% tax-free part,
     * the marginal tax due, and (the trap most people miss) the Month-1 emergency over-deduction
     * and how to reclaim it. Reuses the already-formatted {@see LumpSumTaxShock}
     * array, so the assistant's figures match the tax-shock panel's (provenance).
     *
     * @param  array<string, mixed>  $t
     * @return list<AssistantFact>
     */
    private static function taxShockFacts(array $t): array
    {
        $facts = [
            new AssistantFact('Pension lump sum — age when taken', (string) $t['atAge']),
            new AssistantFact('Pension lump sum — gross amount withdrawn', $t['gross']),
            new AssistantFact('Pension lump sum — tax-free part (up to 25%)', $t['taxFree']),
            new AssistantFact('Pension lump sum — taxable part', $t['taxable']),
            new AssistantFact('Pension lump sum — tax actually due at your marginal rate', $t['marginalTax']),
            new AssistantFact('Pension lump sum — tax taken at source'.($t['emergencyApplied'] ? ' (emergency Month-1 basis)' : ''), $t['taxAtSource']),
        ];

        if ($t['hasOverDeduction']) {
            $label = 'Pension lump sum — over-deducted now, reclaimable'.($t['reclaimForm'] ? " (reclaim with form {$t['reclaimForm']})" : '');
            $facts[] = new AssistantFact($label, $t['overDeduction']);
        }

        $facts[] = new AssistantFact('Pension lump sum — net cash received before any reclaim', $t['netReceived']);
        $facts[] = new AssistantFact('Pension lump sum — money-purchase annual allowance (MPAA) triggered', $t['mpaaTriggered'] ? 'Yes' : 'No');

        return $facts;
    }

    /**
     * The Monte Carlo aggregate as facts — the probabilities and ranges the single central
     * projection can't give. Formatted through the SAME presenter helpers the results page uses
     * ({@see ResultPresenter::formatPercent()} / the longevity + care panels), so a probability
     * the assistant states matches the panel's to the point (provenance). Every label carries the
     * "Monte Carlo —" prefix so the model never confuses a range/probability for the central figure.
     *
     * @return list<AssistantFact>
     */
    private static function monteCarloFacts(SimulationResult $s): array
    {
        $facts = [
            new AssistantFact('Monte Carlo — number of simulated futures', number_format($s->nPaths)),
            new AssistantFact('Monte Carlo — chance your full spending is funded for life', ResultPresenter::formatPercent($s->successProbabilityFullSpend)),
            new AssistantFact('Monte Carlo — chance your essential spending is funded for life', ResultPresenter::formatPercent($s->successProbabilityEssentials)),
            new AssistantFact('Monte Carlo — chance of running out of money', ResultPresenter::formatPercent($s->depletionRate)),
        ];

        if ($s->medianDepletionYear !== null) {
            $facts[] = new AssistantFact('Monte Carlo — if the money runs out, the typical year it happens', (string) $s->medianDepletionYear);
        }

        // Spendable (excl-home) range where available — the honest series; else total wealth.
        $usable = $s->usableWealthPercentiles !== [];
        $p = $usable ? $s->usableWealthPercentiles : $s->terminalWealthPercentiles;
        $basis = $usable ? 'spendable wealth left at the end (excludes the home)' : 'total wealth left at the end (includes the home)';
        if (isset($p['p10'], $p['p50'], $p['p90'])) {
            $facts[] = new AssistantFact("Monte Carlo — {$basis}, pessimistic (10th percentile)", $p['p10']->format());
            $facts[] = new AssistantFact("Monte Carlo — {$basis}, typical (median)", $p['p50']->format());
            $facts[] = new AssistantFact("Monte Carlo — {$basis}, optimistic (90th percentile)", $p['p90']->format());
        }

        $longevity = ResultPresenter::longevityPanel($s->longevity);
        if ($longevity !== null) {
            $facts[] = new AssistantFact('Monte Carlo — last-survivor age, typical (median)', (string) $longevity['ageP50']);
            $facts[] = new AssistantFact('Monte Carlo — last-survivor age range (10th to 90th percentile)', "{$longevity['ageP10']} to {$longevity['ageP90']}");
            $facts[] = new AssistantFact('Monte Carlo — chance at least one of you reaches 95', $longevity['reaches95']);
            $facts[] = new AssistantFact('Monte Carlo — chance at least one of you reaches 100', $longevity['reaches100']);
        }

        if ($s->careImpact !== null) {
            $facts[] = new AssistantFact('Monte Carlo — chance of needing residential or nursing care', ResultPresenter::formatPercent($s->careImpact->shareOfPathsWithCare));
            $facts[] = new AssistantFact('Monte Carlo — typical (median) care bill when it happens', $s->careImpact->medianCareCost->format());
            $facts[] = new AssistantFact('Monte Carlo — high (90th percentile) care bill', $s->careImpact->p90CareCost->format());
        }

        return $facts;
    }

    /**
     * Render the facts + the year-by-year ladder as one block. This is BOTH the context shown to
     * the model and the grounding source {@see FigureGrounding} checks its answer against — one
     * home, so the model can only state figures it was actually shown here (plus any in the
     * reader's question).
     */
    public function promptBlock(): string
    {
        $block = implode("\n", array_map(
            static fn (AssistantFact $f): string => "- {$f->label}: {$f->value}",
            $this->facts,
        ));

        return $this->ladder === '' ? $block : $block."\n\n".$this->ladder;
    }

    /**
     * The full projection, one line per year, every figure inline-labelled — so any per-year
     * question resolves to a figure the model was actually given (and can't mislabel). Reuses the
     * reconciled {@see ResultPresenter::ladder()} rows, so the assistant's per-year figures are the
     * same numbers the ladder panel displays (provenance). Empty when there are no years.
     */
    private static function renderLadder(ForecastResult $forecast): string
    {
        $ladder = ResultPresenter::ladder($forecast);
        if ($ladder['rows'] === []) {
            return '';
        }

        $labels = $ladder['sourceLabels'];
        $lines = [];

        foreach ($ladder['rows'] as $row) {
            $income = [];
            foreach ($row['income'] as $source => $value) {
                if ($value !== '£0.00') { // only the sources that actually paid out that year
                    $income[] = ($labels[$source] ?? $source).' '.$value;
                }
            }
            $incomePart = $income === [] ? '' : ' income ('.implode(', ', $income).');';
            $growthPart = ($ladder['showGrowth'] && $row['investmentGrowth'] !== '£0.00') ? " investment growth {$row['investmentGrowth']};" : '';
            $shortfallPart = $row['shortfall'] !== null ? " shortfall {$row['shortfall']};" : '';
            $ages = $row['ages'] !== '' ? " (age {$row['ages']})" : '';

            $lines[] = "{$row['year']}{$ages}: total spend {$row['spend']} (essentials {$row['essentialSpend']}, discretionary {$row['discretionarySpend']});{$incomePart} tax {$row['tax']};{$growthPart}{$shortfallPart} spendable wealth {$row['usableWealth']}, total wealth {$row['totalWealth']}.";
        }

        return "YEAR-BY-YEAR (real terms, in today's money) — the full projection, so you can answer questions about any specific year:\n".implode("\n", $lines);
    }
}
