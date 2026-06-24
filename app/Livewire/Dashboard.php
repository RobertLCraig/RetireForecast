<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Scenario;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The signed-in user's home: a list of their saved forecasts (scenarios). The
 * scenario builder and result views are added in the next milestones; for now this
 * is the landing the auth flow redirects to and the place new forecasts are started.
 *
 * Full-page Livewire components render into the app's Blade layout component; the
 * Livewire 4 default ({@see config/livewire.php} `layouts::app`) is not used here.
 */
#[Layout('components.layouts.app')]
class Dashboard extends Component
{
    /** @return Collection<int, Scenario> */
    public function scenarios(): Collection
    {
        return auth()->user()
            ->scenarios()
            ->with('household')
            ->latest()
            ->get();
    }

    public function render(): View
    {
        return view('livewire.dashboard', ['scenarios' => $this->scenarios()])
            ->title('Dashboard');
    }
}
