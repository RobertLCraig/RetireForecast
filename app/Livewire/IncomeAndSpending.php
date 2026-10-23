<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DecisionSupport\SustainableSpend;
use App\Forecast\ResultPresenter;
use App\Forecast\ScenarioForecaster;
use App\Models\Scenario;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One plain screen: where the money comes from, and what it lets you spend. Nothing else.
 * It reads the scenario's own housing plan on the expected (central) path, so it shows at once
 * with no Monte Carlo run.
 */
#[Layout('components.layouts.app')]
class IncomeAndSpending extends Component
{
    public Scenario $scenario;

    public function mount(Scenario $scenario): void
    {
        abort_unless($scenario->user_id === auth()->id(), 403);
        $this->scenario = $scenario;
    }

    public function render(ScenarioForecaster $forecaster, SustainableSpend $sustainable): View
    {
        $forecast = $forecaster->deterministicVariants($this->scenario)[$this->scenario->variant->value];
        $safeExtra = $sustainable->forScenario($this->scenario);
        $atSafeSpend = $safeExtra === null ? null : $sustainable->forecastAt($this->scenario, $safeExtra['annual']);

        return view('livewire.income-and-spending', [
            'money' => ResultPresenter::incomeAndSpending($this->scenario->toHousehold(), $forecast, $atSafeSpend),
            'safeExtra' => $safeExtra,
            'plan' => ResultPresenter::strategyLabel($this->scenario->variant->value),
        ]);
    }
}
