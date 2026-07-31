<?php

declare(strict_types=1);

namespace App\DecisionSupport;

use App\Forecast\ScenarioForecaster;
use App\Models\Scenario;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;

/**
 * **What paying for advice would cost this plan** (adviser-parity B1).
 *
 * Now that the engine charges investment costs at all (A1), this question is nearly free to
 * answer and it is one people actually ask. Run the SAME plan twice — once bearing the charges
 * it already bears, once with an adviser's ongoing fee on top — and report the difference in
 * lifetime pounds, in terminal wealth, and in the year the money runs out.
 *
 * **Construction.** The advised side is the DIY side **plus the ongoing advice fee**, and nothing
 * else. That is deliberate: the fee is the one figure that can be benchmarked to a primary source
 * (config/advice.php), whereas how much dearer an advised fund choice is varies far too much
 * between an in-house model portfolio and a whole-of-market tracker to assume for a household.
 * Building the advised total out of an assumed fund uplift would be inventing the larger half of
 * the number. So the difference between the two sides IS the advice fee, compounded — which is
 * exactly what the reader is trying to see.
 *
 * **Framing discipline (see the plan's B1 note).** This is a COST comparison, not a verdict on
 * advice. The honest reading is that advice costing X%/yr has to add more than X%/yr of value to
 * be worth it, and whether it does is a question this tool cannot answer: the most-cited component
 * of adviser value is behavioural (stopping someone selling in a crash), and none of it is
 * modelled here. Any surface showing these figures must say so.
 *
 * Deterministic and synchronous — two forecasts, milliseconds — like {@see SustainableSpend} and
 * {@see ProtectionGap}, so it needs no queue worker and shows before any Monte Carlo run.
 */
final class AdviceCostComparison
{
    public function __construct(private readonly ScenarioForecaster $forecaster) {}

    /**
     * The comparison for this scenario, or **null** when there is nothing to compare: a household
     * whose plan holds no invested money pays no percentage charge on either side, and a panel
     * reporting "£0 either way" would be noise rather than information.
     *
     * $strategy pins the housing variant (as on {@see ProtectionGap}), so the panel and the ladder
     * cannot disagree about which plan is being priced.
     *
     * @return array{
     *     diyChargePct: float,
     *     adviceFeePct: float,
     *     advisedChargePct: float,
     *     source: string,
     *     sourceNote: string,
     *     verifiedOn: string,
     *     isCustomFee: bool,
     *     diy: array{lifetimeCharges: Money, terminalWealth: Money, depletionYear: int|null},
     *     advised: array{lifetimeCharges: Money, terminalWealth: Money, depletionYear: int|null},
     *     extraLifetimeCost: Money,
     *     terminalWealthLost: Money,
     *     yearsOfMoneyLost: int|null
     * }|null
     */
    public function forScenario(Scenario $scenario, ?string $strategy = null): ?array
    {
        $assumptions = $this->forecaster->assumptions($scenario);
        $inputs = $this->variantInputs($scenario, $assumptions, $strategy);

        $fee = $this->feeFor($scenario);
        $diyCharge = $assumptions->investmentCharge();
        $advisedCharge = Percent::fromBasisPoints($diyCharge->basisPoints + $fee->basisPoints);

        $forecaster = new DeterministicForecaster($this->forecaster->config($scenario), new CohortLifeTable);
        $run = fn (AssumptionSet $set): ForecastResult => $forecaster->forecast($inputs['household'], $set, $inputs['settings']);

        $diy = $this->summarise($run($assumptions));
        $advised = $this->summarise($run($assumptions->withInvestmentCharge($advisedCharge)));

        // Nothing invested on either side: no percentage charge bites, so there is nothing to say.
        if ($diy['lifetimeCharges']->isZero() && $advised['lifetimeCharges']->isZero()) {
            return null;
        }

        return [
            'diyChargePct' => $diyCharge->asPercent(),
            'adviceFeePct' => $fee->asPercent(),
            'advisedChargePct' => $advisedCharge->asPercent(),
            'source' => (string) config('advice.ongoing_fee_source'),
            'sourceNote' => (string) config('advice.ongoing_fee_source_note'),
            'verifiedOn' => (string) config('advice.verified_on'),
            'isCustomFee' => $this->customFeeBasisPoints($scenario) !== null,
            'diy' => $diy,
            'advised' => $advised,
            'extraLifetimeCost' => $advised['lifetimeCharges']->minus($diy['lifetimeCharges']),
            'terminalWealthLost' => $diy['terminalWealth']->minus($advised['terminalWealth']),
            // How many years earlier the money runs out. Null when neither side runs out (nothing
            // to report) or when only the advised side does, which the caller renders as its own
            // sentence — "it starts running out at all" is a stronger fact than "N years earlier".
            'yearsOfMoneyLost' => $diy['depletionYear'] !== null && $advised['depletionYear'] !== null
                ? $diy['depletionYear'] - $advised['depletionYear']
                : null,
        ];
    }

    /**
     * The ongoing advice fee to price: the scenario's own figure if the user entered one, else the
     * benchmarked average from config. A blank entry means "use the benchmark", not "no fee".
     */
    private function feeFor(Scenario $scenario): Percent
    {
        return Percent::fromBasisPoints(
            $this->customFeeBasisPoints($scenario) ?? (int) config('advice.ongoing_fee_bp')
        );
    }

    /** The user's own advice fee for this scenario in basis points, or null if they left it blank. */
    private function customFeeBasisPoints(Scenario $scenario): ?int
    {
        $entered = $scenario->effectiveBuilderState()['adviceFeePct'] ?? '';

        if ($entered === '' || $entered === null) {
            return null;
        }

        return (int) round(((float) $entered) * 100);
    }

    /**
     * The three figures a reader compares between the two runs: what holding the money cost over
     * the whole plan, what is left at the end, and when (if ever) it runs out.
     *
     * The lifetime charge is summed from the projection's own per-year `investmentCharges`, so it
     * is the same figure the cashflow ladder totals underneath itself — never a second calculation.
     *
     * @return array{lifetimeCharges: Money, terminalWealth: Money, depletionYear: int|null}
     */
    private function summarise(ForecastResult $forecast): array
    {
        $charges = Money::zero();
        foreach ($forecast->years as $year) {
            $charges = $charges->plus($year->investmentCharges());
        }

        return [
            'lifetimeCharges' => $charges,
            'terminalWealth' => $forecast->terminalTotalWealth,
            'depletionYear' => $forecast->depletionCalendarYear,
        ];
    }

    /**
     * The household as the scenario's own housing choice leaves it, with the settings that go with
     * it — the same resolution {@see ProtectionGap} and {@see SustainableSpend} use, so an advised
     * sell plan is priced as a seller and not as if it stayed put.
     *
     * @return array{household: Household, settings: ForecastSettings}
     */
    private function variantInputs(Scenario $scenario, AssumptionSet $assumptions, ?string $strategy): array
    {
        $all = $this->forecaster->housingComparison($scenario)->variantInputs(
            $scenario->toHousehold(),
            $this->forecaster->settings($scenario),
            $assumptions,
            $scenario->toHousingAction(),
        );

        $variant = $strategy ?? $scenario->effectiveBuilderState()['variant'] ?? 'stay_put';
        $inputs = $all[$variant] ?? $all['stay_put'];

        return ['household' => $inputs['household'], 'settings' => $inputs['settings']];
    }
}
