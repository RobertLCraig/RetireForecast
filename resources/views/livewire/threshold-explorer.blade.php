@php
    $card = 'rounded-lg border border-gray-200 bg-white p-5';
    $th = 'border-b border-gray-200 px-3 py-2 text-left font-medium text-gray-700';
    $td = 'border-b border-gray-100 px-3 py-2 text-gray-800';

    // Word-band chip styling for the care two-state cards (icon + colour never carry meaning alone).
    $careBandColour = [
        'strong' => 'bg-emerald-100 text-emerald-800', 'good' => 'bg-emerald-100 text-emerald-800',
        'borderline' => 'bg-amber-100 text-amber-800',
        'weak' => 'bg-red-100 text-red-800', 'poor' => 'bg-red-100 text-red-800',
    ];
    $careBandIcon = ['strong' => '✓', 'good' => '✓', 'borderline' => '≈', 'weak' => '⚠', 'poor' => '⚠'];

    // The green→red meter split point (as a %), and which side is on-track.
    $crossingPct = $meter && $meter['crossingFrac'] !== null ? round($meter['crossingFrac'] * 100, 1) : null;
    $green = '#86efac';
    $red = '#fca5a5';
    if ($meter) {
        if ($crossingPct === null) {
            // No crossing in range: all on-track (AlreadyOnTrack) or none (Unreachable).
            $track = $meter['verdict'] === 'AlreadyOnTrack' ? $green : $red;
        } elseif ($meter['increasing']) {
            // Later/more is on-track → green on the right.
            $track = "linear-gradient(to right, {$red} 0%, {$red} {$crossingPct}%, {$green} {$crossingPct}%, {$green} 100%)";
        } else {
            // Less is on-track → green on the left.
            $track = "linear-gradient(to right, {$green} 0%, {$green} {$crossingPct}%, {$red} {$crossingPct}%, {$red} 100%)";
        }
    }
@endphp

<section id="sec-how-far" aria-labelledby="how-far-heading" class="{{ $card }} scroll-mt-6">
    <h2 id="how-far-heading" class="text-xl font-semibold text-gray-900">How far can we go?</h2>
    <p class="mt-1 text-sm text-gray-600">
        Pick one thing you could change, drag it, and watch how the money holds up. The line is a
        quick central estimate that updates as you drag; ask for <em>the limit</em> to run the full
        Monte&nbsp;Carlo and see how far you can push before the odds slip below your target.
    </p>

    {{-- Plain-language headline: a 10-dot natural-frequency picture of the current plan's odds,
         year-first, never a bare percentage. Only once a full forecast has run. --}}
    @if ($pictograph)
        <div class="mt-4 rounded-md bg-gray-50 p-4" aria-live="polite">
            <div class="flex items-center gap-1" role="img"
                aria-label="About {{ $pictograph['filled'] }} in 10 possible futures the money covers the essentials to the end.">
                @for ($i = 0; $i < $pictograph['filled']; $i++)
                    <span class="h-4 w-4 rounded-full bg-emerald-500" aria-hidden="true"></span>
                @endfor
                @for ($i = 0; $i < $pictograph['empty']; $i++)
                    <span class="h-4 w-4 rounded-full border border-gray-300 bg-white" aria-hidden="true"></span>
                @endfor
            </div>
            <p class="mt-2 text-sm text-gray-800">
                On your current plan, in about <strong>{{ $pictograph['filled'] }} of 10</strong> possible futures the money covers the essentials right to the end.
                @if ($pictograph['runsOutYear'])
                    In the futures where it doesn't, the money tends to run short around <strong>{{ $pictograph['runsOutYear'] }}</strong>.
                @endif
            </p>
        </div>
    @else
        <p class="mt-4 rounded-md bg-blue-50 px-3 py-2 text-sm text-blue-800" role="status">
            Run a full forecast on this plan to see the odds as a picture here. The line and the limit below work without one.
        </p>
    @endif

    {{-- Lever selector: only the levers this scenario can actually move. --}}
    <div class="mt-5 flex flex-wrap gap-2" role="group" aria-label="Choose what to change">
        @foreach ($levers as $option)
            <button type="button" wire:click="setLever('{{ $option['value'] }}')"
                @class([
                    'rounded-full border px-3 py-1.5 text-sm',
                    'border-blue-600 bg-blue-600 text-white' => $selectedLever === $option['value'],
                    'border-gray-300 text-gray-700 hover:bg-gray-100' => $selectedLever !== $option['value'],
                ])
                @if ($selectedLever === $option['value']) aria-pressed="true" @endif>
                {{ $option['label'] }}
            </button>
        @endforeach
    </div>

    {{-- A consequence of this lever the odds curve cannot show. Working longer pushes a carer over
         the Carer's Allowance earnings limit and postpones the Pension Credit carer addition, which
         the sweep never subtracts. --}}
    @if ($leverCaveat)
        <p class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="note">
            {{ $leverCaveat }}
        </p>
    @endif

    {{-- Continuous levers only: the slider + instant deterministic line. Care is a binary
         (off vs on) with no ordered range to drag, so it shows none of this — see its two-state
         readout under "the limit" below. --}}
    @unless ($isCare)
        {{-- The slider: dragging redraws the deterministic line (a server round-trip, debounced). --}}
        <div class="mt-4">
            <div class="flex items-baseline justify-between">
                <label for="lever-slider" class="text-sm font-medium text-gray-800">{{ $selectedLabel }}</label>
                <span class="text-sm font-semibold text-gray-900" aria-live="polite">{{ $slider['valueLabel'] }}</span>
            </div>
            <input id="lever-slider" type="range" wire:model.live.debounce.400ms="leverValue"
                min="{{ $slider['min'] }}" max="{{ $slider['max'] }}" step="{{ $slider['step'] }}"
                class="mt-2 w-full accent-blue-600"
                aria-valuetext="{{ $slider['valueLabel'] }}">
            <div class="flex justify-between text-xs text-gray-500">
                <span>{{ \App\DecisionSupport\ThresholdPresenter::formatLeverValue($leverKey, (float) $slider['min']) }}</span>
                <span>{{ \App\DecisionSupport\ThresholdPresenter::formatLeverValue($leverKey, (float) $slider['max']) }}</span>
            </div>
        </div>

        {{-- The instant deterministic net-position line. Keyed on the lever + value so a drag
             replaces the subtree and re-inits the chart with the new line; wire:ignore inside keeps
             the threshold poll from disturbing the canvas. --}}
        <div class="mt-4" wire:key="np-{{ $selectedLever }}-{{ $slider['value'] }}">
            <div wire:ignore>
                <div x-data="chart(@js($netPosition['options']))" role="img"
                    aria-label="Central-estimate net position over time at {{ $slider['valueLabel'] }}. The figures are in the table below."></div>
            </div>
        </div>
        <p class="mt-1 text-xs text-gray-500">
            A single central estimate (one likely path), not the full range — it updates instantly as you drag.
            @if ($netPosition['runsOutYear'])
                On this estimate the money runs short around <strong>{{ $netPosition['runsOutYear'] }}</strong>; the line dips below £0 to show the shortfall.
            @else
                On this estimate the money lasts to the end.
            @endif
        </p>

        <details class="mt-3">
            <summary class="cursor-pointer text-sm font-medium text-blue-700">Show the numbers behind this line</summary>
            <div class="mt-2 max-h-72 overflow-auto" tabindex="0">
                <table class="w-full text-sm">
                    <caption class="sr-only">Central-estimate net position (real pounds) by calendar year at {{ $slider['valueLabel'] }}</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="{{ $th }}">Year</th>
                            <th scope="col" class="{{ $th }}">Net position</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($netPosition['rows'] as $row)
                            <tr>
                                <th scope="row" class="{{ $td }} font-medium">{{ $row['year'] }}</th>
                                <td class="{{ $td }}">{{ $row['net'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @else
        <p class="mt-4 text-sm text-gray-700">
            This one is a straight before/after, not a dial: run it to see the same plan simulated with the
            late-life care-fee tail left out and put in, side by side.
        </p>
    @endunless

    {{-- The limit: the queued Monte Carlo threshold + its green→red meter (or, for care, the
         two-state before/after). --}}
    <div class="mt-6 border-t border-gray-100 pt-5">
        @if (! $threshold || ($threshold->status !== \App\Enums\SimulationStatus::Done && $threshold->status->isTerminal()))
            @if ($threshold && $threshold->status === \App\Enums\SimulationStatus::Failed)
                <p role="alert" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-800">Finding the limit failed: {{ $threshold->error }}</p>
            @elseif ($threshold && $threshold->status === \App\Enums\SimulationStatus::Cancelled)
                <p class="mb-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">That run was cancelled. Try again when ready.</p>
            @endif
            <button type="button" wire:click="findLimit"
                class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                {{ $isCare ? 'Compare care off vs care on' : 'Find the limit for “'.$selectedLabel.'”' }}
            </button>
            <p class="mt-1 text-xs text-gray-500">Runs the full Monte&nbsp;Carlo across the range in the background — a minute or so.</p>
        @elseif (! $threshold->status->isTerminal())
            <div wire:poll.1500ms="pollThreshold">
                <div class="flex items-center justify-between text-sm text-gray-700">
                    <span>Finding the limit — {{ ucfirst($threshold->status->value) }} {{ $threshold->progress_pct }}%</span>
                    <button type="button" wire:click="cancelLimit" class="text-red-700 underline">Cancel</button>
                </div>
                <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-200"
                    role="progressbar" aria-valuenow="{{ $threshold->progress_pct }}" aria-valuemin="0" aria-valuemax="100"
                    aria-label="Threshold progress">
                    <div class="h-full bg-blue-600 transition-all" style="width: {{ $threshold->progress_pct }}%"></div>
                </div>
                @if ($threshold->isAwaitingWorker())
                    <p role="status" class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        Still waiting for a background worker. If you're running locally, start one with <code class="font-mono">php artisan queue:work</code>.
                    </p>
                @endif
            </div>
        @elseif ($isCare && $careComparison)
            {{-- Care: a pinned before/after. Two independently-seeded runs, read side by side —
                 no meter, no line between them (there is no "half of care"). --}}
            <div aria-live="polite">
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($careComparison['states'] as $state)
                        <div class="{{ $card }}">
                            <h3 class="text-sm font-semibold text-gray-900">{{ $state['label'] }}</h3>
                            <div class="mt-3 flex items-center gap-1" role="img"
                                aria-label="About {{ $state['pictograph']['filled'] }} in 10 possible futures the money covers the essentials to the end, {{ $state['label'] }}.">
                                @for ($i = 0; $i < $state['pictograph']['filled']; $i++)
                                    <span class="h-4 w-4 rounded-full bg-emerald-500" aria-hidden="true"></span>
                                @endfor
                                @for ($i = 0; $i < $state['pictograph']['empty']; $i++)
                                    <span class="h-4 w-4 rounded-full border border-gray-300 bg-white" aria-hidden="true"></span>
                                @endfor
                            </div>
                            <p class="mt-2 text-sm text-gray-800">
                                In about <strong>{{ $state['pictograph']['filled'] }} of 10</strong> futures the money covers the essentials to the end.
                            </p>
                            <span class="mt-3 inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-sm font-medium {{ $careBandColour[$state['band']['level']] ?? 'bg-gray-100 text-gray-800' }}">
                                <span aria-hidden="true">{{ $careBandIcon[$state['band']['level']] ?? '•' }}</span>
                                {{ $state['band']['word'] }}
                            </span>
                        </div>
                    @endforeach
                </div>

                <p class="mt-3 text-sm text-gray-800">{{ $careComparison['deltaCaption'] }}</p>
                <p class="mt-2 text-xs text-gray-500">{{ $careComparison['caption'] }}</p>
                <button type="button" wire:click="findLimit" class="mt-2 text-xs text-blue-700 underline">Recompute</button>

                {{-- Analyst drill-down: each state's success probability with its confidence interval + CSV. --}}
                <details class="mt-4">
                    <summary class="cursor-pointer text-sm font-medium text-blue-700">Show the numbers</summary>
                    <div class="mt-2 flex items-center justify-between">
                        <p class="text-xs text-gray-500">Each state is a full Monte&nbsp;Carlo run; the range is its 95% confidence interval. The two runs use independent random draws, so read the difference as real only when the ranges do not overlap.</p>
                        <a href="{{ $csvUrl }}" class="text-sm text-blue-700 underline">Download CSV</a>
                    </div>
                    <div class="mt-2 overflow-x-auto" tabindex="0">
                        <table class="w-full text-sm">
                            <caption class="sr-only">Chance the money lasts with care fees left out vs modelled, each with its 95% confidence interval and paths</caption>
                            <thead>
                                <tr>
                                    <th scope="col" class="{{ $th }}">Care fees</th>
                                    <th scope="col" class="{{ $th }}">Chance it lasts</th>
                                    <th scope="col" class="{{ $th }}">Low</th>
                                    <th scope="col" class="{{ $th }}">High</th>
                                    <th scope="col" class="{{ $th }}">Paths</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($careComparison['states'] as $state)
                                    <tr>
                                        <th scope="row" class="{{ $td }} font-medium">{{ $state['key'] === 'on' ? 'Modelled' : 'Not modelled' }}</th>
                                        <td class="{{ $td }}">{{ round($state['p'] * 100, 1) }}%</td>
                                        <td class="{{ $td }}">{{ round($state['ciLow'] * 100, 1) }}%</td>
                                        <td class="{{ $td }}">{{ round($state['ciHigh'] * 100, 1) }}%</td>
                                        <td class="{{ $td }}">{{ number_format($state['paths']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>
        @elseif ($meter)
            {{-- The meter: how far the lever can move before the odds slip below target. --}}
            <div aria-live="polite">
                <div class="flex items-center gap-2">
                    @if ($meter['onTrack'])
                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-1 text-sm font-medium text-emerald-800">
                            <span aria-hidden="true">✓</span> On track at this setting
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-1 text-sm font-medium text-red-800">
                            <span aria-hidden="true">⚠</span> Past the limit at this setting
                        </span>
                    @endif
                    @if ($meter['crossingLabel'])
                        <span class="text-sm text-gray-600">The line is around <strong>{{ $meter['crossingLabel'] }}</strong>.</span>
                    @endif
                </div>

                <div class="relative mt-3 h-4 w-full rounded-full" style="background: {{ $track }};" role="img"
                    aria-label="Meter: {{ $meter['caption'] }}">
                    {{-- Current setting marker --}}
                    <div class="absolute top-[-4px] h-6 w-1 rounded bg-gray-900"
                        style="left: calc({{ round($meter['currentFrac'] * 100, 2) }}% - 2px);"></div>
                </div>
                <div class="mt-1 flex justify-between text-xs text-gray-500">
                    <span>{{ $meter['minLabel'] }}</span>
                    <span>{{ $meter['maxLabel'] }}</span>
                </div>

                <p class="mt-3 text-sm text-gray-800">{{ $meter['caption'] }}</p>
                @if ($meter['bandLow'] && $meter['bandHigh'])
                    <p class="mt-1 text-xs text-gray-500">The exact crossing is somewhere between {{ $meter['bandLow'] }} and {{ $meter['bandHigh'] }} (Monte&nbsp;Carlo leaves a little uncertainty).</p>
                @endif
                <button type="button" wire:click="findLimit" class="mt-2 text-xs text-blue-700 underline">Recompute</button>
            </div>

            {{-- Analyst drill-down: the full success-probability sweep + the grid + CSV. --}}
            @if ($sCurve)
                <details class="mt-4">
                    <summary class="cursor-pointer text-sm font-medium text-blue-700">Show the full sweep (the numbers)</summary>
                    <div class="mt-3" wire:key="scurve-{{ $threshold->id }}">
                        <div wire:ignore>
                            <div x-data="chart(@js($sCurve['options']))" role="img"
                                aria-label="The chance the money lasts at each setting of {{ $selectedLabel }}, with your target marked. The figures are in the table below."></div>
                        </div>
                    </div>
                    <div class="mt-2 flex items-center justify-between">
                        <p class="text-xs text-gray-500">Each point is a full Monte&nbsp;Carlo run; the range is its 95% confidence interval.</p>
                        <a href="{{ $csvUrl }}" class="text-sm text-blue-700 underline">Download CSV</a>
                    </div>
                    <div class="mt-2 overflow-x-auto" tabindex="0">
                        <table class="w-full text-sm">
                            <caption class="sr-only">Chance the money lasts at each {{ $selectedLabel }} setting, with the 95% confidence interval and paths per point</caption>
                            <thead>
                                <tr>
                                    <th scope="col" class="{{ $th }}">{{ $selectedLabel }}</th>
                                    <th scope="col" class="{{ $th }}">Chance it lasts</th>
                                    <th scope="col" class="{{ $th }}">Low</th>
                                    <th scope="col" class="{{ $th }}">High</th>
                                    <th scope="col" class="{{ $th }}">Paths</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($sCurve['rows'] as $row)
                                    <tr>
                                        <th scope="row" class="{{ $td }} font-medium">{{ $row['value'] }}</th>
                                        <td class="{{ $td }}">{{ $row['p'] }}%</td>
                                        <td class="{{ $td }}">{{ $row['ciLow'] }}%</td>
                                        <td class="{{ $td }}">{{ $row['ciHigh'] }}%</td>
                                        <td class="{{ $td }}">{{ number_format($row['paths']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endif
        @endif
    </div>

    {{-- The 2-D trade-off map (Phase 5): the buy-price ceiling at each retirement age, because a
         single-lever limit reads as unconditional ("£260k" hides "at 67; £300k at 70"). Offered
         only when both axes are real levers for this scenario. --}}
    @if ($frontierOffered)
        <div class="mt-6 border-t border-gray-100 pt-5">
            <h3 class="text-base font-semibold text-gray-900">The trade-off map: home price × retirement age</h3>
            <p class="mt-1 text-sm text-gray-600">
                A single limit hides a pairing: how much the new home can cost depends on when the working
                partner retires. This maps the two together — every cell of the map is its own full
                Monte&nbsp;Carlo run on the same pinned draws.
            </p>

            @if (! $frontier || ($frontier->status !== \App\Enums\SimulationStatus::Done && $frontier->status->isTerminal()))
                @if ($frontier && $frontier->status === \App\Enums\SimulationStatus::Failed)
                    <p role="alert" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-800">Mapping the trade-off failed: {{ $frontier->error }}</p>
                @elseif ($frontier && $frontier->status === \App\Enums\SimulationStatus::Cancelled)
                    <p class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">That run was cancelled. Try again when ready.</p>
                @endif
                <button type="button" wire:click="mapFrontier"
                    class="mt-3 rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                    Map the trade-off
                </button>
                <p class="mt-1 text-xs text-gray-500">Runs a full Monte&nbsp;Carlo for every cell of the map in the background — expect several minutes.</p>
            @elseif (! $frontier->status->isTerminal())
                <div wire:poll.1500ms="pollThreshold" class="mt-3">
                    <div class="flex items-center justify-between text-sm text-gray-700">
                        <span>Mapping the trade-off — {{ ucfirst($frontier->status->value) }} {{ $frontier->progress_pct }}%</span>
                        <button type="button" wire:click="cancelFrontier" class="text-red-700 underline">Cancel</button>
                    </div>
                    <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-200"
                        role="progressbar" aria-valuenow="{{ $frontier->progress_pct }}" aria-valuemin="0" aria-valuemax="100"
                        aria-label="Trade-off map progress">
                        <div class="h-full bg-blue-600 transition-all" style="width: {{ $frontier->progress_pct }}%"></div>
                    </div>
                    @if ($frontier->isAwaitingWorker())
                        <p role="status" class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            Still waiting for a background worker. If you're running locally, start one with <code class="font-mono">php artisan queue:work</code>.
                        </p>
                    @endif
                </div>
            @elseif ($frontierView)
                <div aria-live="polite">
                    <p class="mt-3 text-sm text-gray-800">{{ $frontierView['summary'] }}</p>

                    {{-- One chip per held retirement age: the price ceiling there, banded and honest. --}}
                    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($frontierView['columns'] as $column)
                            <div class="rounded-md border border-gray-200 px-3 py-2 text-sm">
                                <span class="font-medium text-gray-900">{{ ucfirst($column['condition']) }}:</span>
                                <span class="text-gray-800">{{ $column['chip'] }}</span>
                                @if ($column['ceiling'] && $column['bandLow'] && $column['bandHigh'])
                                    <span class="block text-xs text-gray-500">the crossing sits between {{ $column['bandLow'] }} and {{ $column['bandHigh'] }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <button type="button" wire:click="mapFrontier" class="mt-2 text-xs text-blue-700 underline">Recompute</button>

                    {{-- Analyst drill-down: every measured cell + CSV. The tint boundary IS the
                         iso-line, and each cell carries its percentage as text (never colour alone). --}}
                    <details class="mt-4">
                        <summary class="cursor-pointer text-sm font-medium text-blue-700">Show the full map (the numbers)</summary>
                        <div class="mt-2 flex items-center justify-between gap-4">
                            <p class="text-xs text-gray-500">
                                Each cell is a full Monte&nbsp;Carlo run of {{ number_format($frontierView['pathsPerCell']) }} futures:
                                the chance the money lasts at that pairing. Tinted cells at or above your
                                {{ $frontierView['targetPct'] }} target sit on the on-track side; the boundary between the
                                two tints is the limit.
                            </p>
                            <a href="{{ $frontierCsvUrl }}" class="shrink-0 text-sm text-blue-700 underline">Download CSV</a>
                        </div>
                        <div class="mt-2 overflow-x-auto" tabindex="0">
                            <table class="w-full text-sm">
                                <caption class="sr-only">Chance the money lasts for each pairing of {{ $frontierView['thresholdLabel'] }} and {{ $frontierView['conditionLabel'] }}</caption>
                                <thead>
                                    <tr>
                                        <th scope="col" class="{{ $th }}">{{ $frontierView['thresholdLabel'] }}</th>
                                        @foreach ($frontierView['grid']['conditionLabels'] as $label)
                                            <th scope="col" class="{{ $th }}">{{ ucfirst($label) }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($frontierView['grid']['rows'] as $row)
                                        <tr>
                                            <th scope="row" class="{{ $td }} font-medium">{{ $row['label'] }}</th>
                                            @foreach ($row['cells'] as $cell)
                                                <td class="{{ $td }} {{ $cell['above'] ? 'bg-emerald-50 text-emerald-900' : 'bg-red-50 text-red-900' }}">{{ $cell['pct'] }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                </div>
            @endif
        </div>
    @endif

    <p class="mt-4 text-xs text-gray-500">
        Guidance only, not a personal recommendation. These figures show the consequences of the inputs and assumptions you entered.
        Free, impartial help: <a href="https://www.moneyhelper.org.uk" class="underline">MoneyHelper</a> and Pension&nbsp;Wise.
    </p>
</section>
