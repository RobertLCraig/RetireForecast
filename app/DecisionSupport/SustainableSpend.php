<?php

declare(strict_types=1);

namespace App\DecisionSupport;

use App\Forecast\ScenarioForecaster;
use App\Models\Scenario;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Sweep\Lever\DiscretionarySpendLever;

/**
 * **How much could they actually afford to spend on the things they choose?**
 *
 * Every other figure this tool reports is bounded by the budget the user entered: the projection
 * spends what it was told to spend, so a "monthly allowance" read off a projection can only echo
 * the input back and say whether it worked. That answers "does my plan hold?" — it does NOT answer
 * "what could I spend?", which is the question a reader actually arrives with.
 *
 * This searches for the answer instead of reading it: bisect the discretionary-spend lever
 * ({@see DiscretionarySpendLever}) for the highest annual discretionary spend at which the plan
 * still holds. Success is monotone decreasing in spend — spending more can only make the money run
 * out sooner — which is what makes bisection valid rather than a lucky guess.
 *
 * **Variant-aware.** The scenario's own housing choice is applied FIRST (sell, buy cheaper, rent),
 * then the lever is applied to that household — so a "sell & rent" plan is searched as a renter,
 * not as if it stayed put. Reading a sell plan off the stay-put path is a live trap in this
 * codebase; this class does not fall into it.
 *
 * It runs on the DETERMINISTIC projection, so it is synchronous and needs no queue worker (~20
 * forecasts per scenario, each milliseconds). That also means it inherits the deterministic path's
 * optimism — it walks the central estimate, not the unlucky tail — so the figure is "what the
 * expected path supports". Any surface showing it must say so, and show it beside the Monte Carlo
 * "how sure" figure rather than instead of it.
 */
final class SustainableSpend
{
    /** Bisection steps: 20 halvings resolve any bracket below the ceiling to pennies. */
    private const STEPS = 20;

    /** Never search above this annual discretionary spend, whatever the household's wealth. */
    private const CEILING = 500_000.0;

    public function __construct(private readonly ScenarioForecaster $forecaster) {}

    /**
     * The most the household could spend a year on discretionary things while the plan still holds,
     * and the same figure per month.
     *
     * **The bar is that the FULL budget is funded every single year, and the money never runs out.**
     * That is what "could afford to spend" has to mean: money they can actually spend, every year,
     * not a figure they could write down. An essentials-only bar was tried and is **degenerate** —
     * where income alone covers the essential floor, the household meets its essentials no matter
     * how large a discretionary budget is set (the excess simply goes unfunded), so the search is
     * insensitive to the lever and runs to its ceiling. Do not reintroduce it.
     *
     * Returns **null** when even zero discretionary spend fails: the plan cannot cover essentials at
     * any level of restraint. Reporting "£0" there would say "no room for treats" when the truth is
     * "this plan is broken" — a distinction callers must render differently.
     *
     * @return array{annual: Money, monthly: Money, ceilingHit: bool}|null
     */
    public function forScenario(Scenario $scenario): ?array
    {
        // Resolve the scenario's own housing variant ONCE — it does not change as the lever moves,
        // and rebuilding it per probe would cost ~20 sale/purchase decompositions for nothing.
        $variant = $scenario->effectiveBuilderState()['variant'] ?? 'stay_put';
        $assumptions = $this->forecaster->assumptions($scenario);
        $inputs = $this->forecaster->housingComparison($scenario)->variantInputs(
            $scenario->toHousehold(),
            $this->forecaster->settings($scenario),
            $assumptions,
            $scenario->toHousingAction(),
        )[$variant];

        $forecaster = new DeterministicForecaster($this->forecaster->config($scenario), new CohortLifeTable);
        $lever = new DiscretionarySpendLever;

        $holds = function (float $spend) use ($lever, $inputs, $forecaster, $assumptions): bool {
            $swept = $lever->apply($inputs['household'], $inputs['settings'], max(0.0, $spend));
            $forecast = $forecaster->forecast($swept->household, $assumptions, $swept->settings);

            // Money must last AND every year's full budget must actually be funded. The second
            // condition is what makes the search sensitive to the lever at all.
            return $forecast->depletionCalendarYear === null
                && $forecast->essentialsAlwaysMet
                && $forecast->fullSpendAlwaysMet;
        };

        // A plan that fails on essentials alone has no allowance to report.
        if (! $holds(0.0)) {
            return null;
        }

        // Bracket the answer by doubling, so a wealthy household is not capped by an arbitrary
        // starting guess and a stretched one is not searched over a pointlessly wide range.
        $low = 0.0;
        $high = 1_000.0;
        while ($high < self::CEILING && $holds($high)) {
            $low = $high;
            $high *= 2.0;
        }

        // Everything up to the ceiling holds: report the ceiling and FLAG it, rather than implying a
        // precise limit the search never found.
        if ($high >= self::CEILING && $holds(self::CEILING)) {
            return self::result(self::CEILING, true);
        }

        // Bisect. Invariant: $low holds, $high does not.
        for ($i = 0; $i < self::STEPS; $i++) {
            $mid = ($low + $high) / 2.0;
            if ($holds($mid)) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return self::result($low, false);
    }

    /**
     * @return array{annual: Money, monthly: Money, ceilingHit: bool}
     */
    private static function result(float $annualPounds, bool $ceilingHit): array
    {
        $annualPence = (int) round($annualPounds * 100);

        return [
            'annual' => Money::fromPence($annualPence),
            // Divide once from the annual pence, so annual and monthly cannot disagree.
            'monthly' => Money::fromPence(intdiv($annualPence, 12)),
            'ceilingHit' => $ceilingHit,
        ];
    }
}
