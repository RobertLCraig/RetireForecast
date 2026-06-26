<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ScenarioStatus;
use App\Models\Scenario;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The signed-in user's home: their saved (ready) forecasts, each opening to its results
 * or the builder to edit, plus a prompt to resume any in-progress draft.
 *
 * Full-page Livewire components render into the app's Blade layout component; the
 * Livewire 4 default ({@see config/livewire.php} `layouts::app`) is not used here.
 */
#[Layout('components.layouts.app')]
class Dashboard extends Component
{
    /**
     * The user's saved (ready) base forecasts, newest first, each with its delta-child
     * what-ifs nested under it. Children are not listed at the top level — they belong
     * to their base (Phase C2).
     *
     * @return Collection<int, Scenario>
     */
    public function scenarios(): Collection
    {
        return auth()->user()
            ->scenarios()
            ->where('status', ScenarioStatus::Ready)
            ->whereNull('parent_scenario_id')
            ->with(['children' => fn ($q) => $q->where('status', ScenarioStatus::Ready)->latest()])
            ->latest()
            ->get();
    }

    /** The user's single in-progress draft, if one is waiting to be resumed. */
    public function draft(): ?Scenario
    {
        return auth()->user()
            ->scenarios()
            ->where('status', ScenarioStatus::Draft)
            ->latest()
            ->first();
    }

    public function render(): View
    {
        return view('livewire.dashboard', [
            'scenarios' => $this->scenarios(),
            'draft' => $this->draft(),
        ])->title('Dashboard');
    }
}
