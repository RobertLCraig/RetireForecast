<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Models\Scenario;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;

/**
 * Which housing strategy the year-by-year cashflow is read on, and the deterministic forecast
 * for each strategy the inputs make meaningful.
 *
 * One home for the choice: the results page, its CSV export and the printable PDF all resolve
 * the strategy through here, so a printed ladder can never be projected on a different strategy
 * from the one the screen showed. A strategy is only OFFERED where the inputs configure it (a
 * sale price for rent, a buy price for buy-outright), and a preference outside that set is
 * clamped to stay put rather than silently projecting a sale the user never entered — the trap
 * that has bitten this codebase twice (see HANDOVER "deterministic() ignores the variant").
 */
final class LadderContext
{
    /**
     * @param  array<string, ForecastResult>  $forecasts  one deterministic forecast per variant key
     * @param  list<array{key: string, label: string}>  $strategies  the strategies worth offering
     * @param  string  $selected  the chosen strategy, clamped to one of $strategies
     */
    private function __construct(
        public readonly array $forecasts,
        public readonly array $strategies,
        public readonly string $selected,
    ) {}

    /**
     * @param  string|null  $preferred  the strategy to open on (defaults to the scenario's own
     *                                  chosen variant); clamped to an offered strategy.
     */
    public static function for(ScenarioForecaster $forecaster, Scenario $scenario, ?string $preferred = null): self
    {
        $forecasts = $forecaster->deterministicVariants($scenario);
        $action = $scenario->toHousingAction();

        // Offer a strategy only where the inputs make it meaningful: stay put always; sell &
        // buy cheaper only with a buy price; sell & rent only when a sale is configured — the
        // same gating the sale explainer / assumptions panel already use for the buy/sale rows.
        $saleConfigured = $action->salePrice->isPositive();
        $strategies = [['key' => 'stay_put', 'label' => ResultPresenter::strategyLabel('stay_put')]];
        if ($saleConfigured && $action->buyPrice !== null && $action->buyPrice->isPositive()) {
            $strategies[] = ['key' => 'buy_outright', 'label' => ResultPresenter::strategyLabel('buy_outright')];
        }
        if ($saleConfigured) {
            $strategies[] = ['key' => 'rent', 'label' => ResultPresenter::strategyLabel('rent')];
        }

        $preferred ??= $scenario->variant->value;
        $offered = array_column($strategies, 'key');
        $selected = in_array($preferred, $offered, true) ? $preferred : 'stay_put';

        return new self($forecasts, $strategies, $selected);
    }

    /** The forecast for the selected strategy — what the ladder, charts and milestones read. */
    public function selectedForecast(): ForecastResult
    {
        return $this->forecasts[$this->selected];
    }

    /**
     * The household exactly as entered (always stay put): what the income-floor and
     * input-sanity readouts are computed on, whatever strategy the ladder is showing.
     */
    public function stayPutForecast(): ForecastResult
    {
        return $this->forecasts['stay_put'];
    }

    /** True when the selected strategy frees the home's value at year 0 (never stay put). */
    public function homeSold(): bool
    {
        return in_array($this->selected, ['buy_outright', 'rent'], true);
    }

    public function selectedLabel(): string
    {
        return ResultPresenter::strategyLabel($this->selected);
    }
}
