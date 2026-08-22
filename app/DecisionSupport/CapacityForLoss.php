<?php

declare(strict_types=1);

namespace App\DecisionSupport;

use App\Forecast\ScenarioForecaster;
use App\Models\Scenario;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Money\RoundingMode;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Sweep\Lever\WealthFallLever;

/**
 * **Capacity for loss: how far could wealth fall before the essentials stop being paid?**
 *
 * The tool already says whether the plan holds and how sure that is. Neither answers the question
 * an adviser is required to ask before anyone takes investment risk: how much of a loss could this
 * household actually absorb before it hurts? A plan can be very likely to work and still have no
 * room at all — and the reader cannot tell the two apart from a probability.
 *
 * So: search for the answer rather than assert it. Mark everything the household owns down by the
 * same fraction on the base date ({@see WealthFallLever}) and binary-search the largest fall at
 * which the **essential spending floor is still met in every year of the projection**. That bar,
 * not "the money lasts", is what capacity for loss means: the floor is what the household cannot
 * do without, and a plan whose discretionary spending is squeezed has not run out of capacity.
 * Losing more can only make the floor harder to meet, which is what makes the search valid.
 *
 * **The answer is a whole percentage, rounded DOWN, and the cash figure is derived from it.** The
 * search is over integer percentages, so the figure reported is one the projection was actually
 * run at and passed — never an interpolated point nobody measured — and the pounds are that same
 * percentage of total wealth, so the two can never disagree. Total wealth is the project's one
 * definition, net of the mortgage ({@see WealthFallLever::baseWealth}).
 *
 * **Variant-aware.** The plan on display is resolved first, so a sell-and-rent plan is stressed as
 * a renter with the proceeds invested, and a stay-put plan as an owner. Deterministic and
 * synchronous like {@see SustainableSpend} and {@see ProtectionGap} — about eight forecasts — so
 * it inherits the central path's optimism: it is what the EXPECTED path could absorb, and any
 * surface showing it must say so.
 */
final class CapacityForLoss
{
    public function __construct(private readonly ScenarioForecaster $forecaster) {}

    /**
     * The biggest across-the-board fall in wealth this plan could take and still meet its
     * essential spending every year.
     *
     * `percent` is that fall as a whole percentage of total wealth and `cash` is the same fall in
     * pounds; `wealth` is the total it is measured against, so a reader can check the arithmetic.
     * `survivesTotalLoss` is true when even losing everything leaves the essentials covered —
     * income alone carries the floor — and `percent` is then 100.
     *
     * `alreadyBreached` is the OTHER end, and the reason this never returns null: a plan that runs
     * short of its essentials at some point as it stands has no room to lose anything at all, and
     * that is the most important thing on the panel, not a reason to hide it. `percent` is then 0
     * and the surface must say WHICH of the two zero-ish answers it is showing — "no room left"
     * reads very differently from "already short".
     *
     * @return array{percent: int, cash: Money, wealth: Money, survivesTotalLoss: bool, alreadyBreached: bool}
     */
    public function forScenario(Scenario $scenario, ?string $strategy = null): array
    {
        ['household' => $household, 'settings' => $settings, 'assumptions' => $assumptions]
            = $this->forecaster->variantInputs($scenario, $strategy);

        $forecaster = new DeterministicForecaster($this->forecaster->config($scenario), new CohortLifeTable);
        $lever = new WealthFallLever;

        $holds = function (int $percent) use ($lever, $household, $settings, $assumptions, $forecaster): bool {
            $fallen = $lever->apply($household, $settings, $percent / 100);

            return $forecaster->forecast($fallen->household, $assumptions, $fallen->settings)->essentialsAlwaysMet;
        };

        $wealth = WealthFallLever::baseWealth($household, $settings);

        if (! $holds(0)) {
            return self::result(0, $wealth, survivesTotalLoss: false, alreadyBreached: true);
        }

        if ($holds(100)) {
            return self::result(100, $wealth, survivesTotalLoss: true, alreadyBreached: false);
        }

        // Invariant: the plan holds at $low and fails at $high. Seven halvings settle it.
        $low = 0;
        $high = 100;
        while ($high - $low > 1) {
            $mid = intdiv($low + $high, 2);
            if ($holds($mid)) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return self::result($low, $wealth, survivesTotalLoss: false, alreadyBreached: false);
    }

    /**
     * @return array{percent: int, cash: Money, wealth: Money, survivesTotalLoss: bool, alreadyBreached: bool}
     */
    private static function result(int $percent, Money $wealth, bool $survivesTotalLoss, bool $alreadyBreached): array
    {
        return [
            'percent' => $percent,
            // Derived from the reported percentage, never solved separately, so the two figures
            // are the same quantity. Rounded down for the same reason the percentage is: a
            // capacity rounded up is a loss the plan was never shown to survive.
            'cash' => $wealth->applyRate(Percent::fromPercent($percent), RoundingMode::Floor),
            'wealth' => $wealth,
            'survivesTotalLoss' => $survivesTotalLoss,
            'alreadyBreached' => $alreadyBreached,
        ];
    }
}
