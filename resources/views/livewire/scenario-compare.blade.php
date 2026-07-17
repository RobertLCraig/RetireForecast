<div>
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Compare what-ifs</h1>
            <p class="mt-1 text-sm text-gray-500">Base plan: {{ $base->name }}</p>
        </div>
        <div class="flex items-center gap-3">
            <button type="button" wire:click="runFullFamily" wire:loading.attr="disabled" wire:target="runFullFamily"
                @disabled($familyRun['active'])
                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-100 disabled:opacity-50"
                title="Queue a fresh 10,000-path Monte Carlo run for every plan here — handy after a model change so each plan's results page shows current figures.">
                <span wire:loading.remove wire:target="runFullFamily">{{ $familyRun['active'] ? 'Running…' : 'Re-run all '.$plans->count().' (full 10k)' }}</span>
                <span wire:loading wire:target="runFullFamily">Queuing…</span>
            </button>
            <a href="{{ route('scenarios.child', $base) }}" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Create a what-if</a>
            <a href="{{ route('dashboard') }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">Back to forecasts</a>
        </div>
    </div>

    <p class="mt-4 max-w-3xl text-sm text-gray-600">
        Each plan below is shown using its central (best-estimate) projection, so the figures appear without
        running a full simulation. A what-if changes one or more values on the base plan; everything it does not
        change tracks the base.
    </p>

    {{-- Live progress for the "re-run all" batch: each plan's 10,000-path run, polled until
         every one lands in a terminal state, so a background Monte Carlo run is never silent.
         The panel (and the polling) show only while runs are tracked. --}}
    @if (! empty($familyRun['rows']))
        <div @if ($familyRun['active']) wire:poll.1500ms="refreshFamily" @endif
             class="mt-4 max-w-3xl rounded-md border border-gray-200 bg-white p-4" role="status" aria-live="polite">
            <div class="flex items-center justify-between gap-3">
                <p class="text-sm font-medium text-gray-800">
                    @if ($familyRun['active'])
                        Running {{ $familyRun['total'] }} full {{ $familyRun['total'] === 1 ? 'simulation' : 'simulations' }} — {{ $familyRun['done'] }} of {{ $familyRun['total'] }} done
                    @elseif ($familyRun['failed'] > 0)
                        All {{ $familyRun['total'] }} {{ $familyRun['total'] === 1 ? 'run' : 'runs' }} finished — {{ $familyRun['failed'] }} did not complete.
                    @else
                        All {{ $familyRun['total'] }} {{ $familyRun['total'] === 1 ? 'run' : 'runs' }} finished.
                    @endif
                </p>
                @if ($familyRun['active'])
                    <button type="button" wire:click="cancelFamily" class="shrink-0 text-sm text-red-700 underline">Cancel all</button>
                @endif
            </div>

            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-200"
                 role="progressbar" aria-valuenow="{{ $familyRun['overallPct'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Overall forecast progress">
                <div class="h-full bg-blue-600 transition-all" style="width: {{ $familyRun['overallPct'] }}%"></div>
            </div>

            <ul class="mt-3 space-y-2">
                @foreach ($familyRun['rows'] as $row)
                    <li class="text-xs">
                        <div class="flex items-center justify-between gap-2 text-gray-600">
                            <span class="truncate font-medium text-gray-700">{{ $row['name'] }}</span>
                            <span class="shrink-0 tabular-nums">{{ $row['status'] }}@if (! $row['terminal']) — {{ $row['pct'] }}%@endif</span>
                        </div>
                        <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
                            <div class="h-full transition-all {{ $row['failed'] ? 'bg-amber-500' : 'bg-blue-500' }}" style="width: {{ $row['pct'] }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($familyRun['awaitingWorker'])
                <p class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    Still queued. The full run needs a background worker — start one with <code>php artisan queue:work</code> (JIT flags in the handover), then this picks up automatically.
                </p>
            @endif

            @unless ($familyRun['active'])
                <p class="mt-3 text-xs text-gray-500">Open any plan's results to see its refreshed Monte Carlo — this comparison already reflects the current model.</p>
            @endunless
        </div>
    @endif

    {{-- Walled-off, advice-style "why" narrative ranking the plans. Built only when the
         `interpret` ability allows (on in personal-use mode); every directive sentence
         originates in App\Compliance\Interpretation. --}}
    @if (! empty($narrative))
        <div class="mt-6">
            @include('livewire.partials.interpretation', ['interpretation' => $narrative])
        </div>
    @endif

    <div class="mt-6 overflow-x-auto rounded-lg border border-gray-200 bg-white" tabindex="0">
        @php $showIht = collect($plans)->contains(fn ($p) => $p['ihtDue'] !== null); @endphp
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <caption class="sr-only">Your base plan and its what-ifs compared on their central projection.</caption>
            <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                <tr>
                    <th scope="col" class="px-4 py-3">Plan</th>
                    <th scope="col" class="px-4 py-3">Housing choice</th>
                    <th scope="col" class="px-4 py-3">Essentials covered every year</th>
                    <th scope="col" class="px-4 py-3">Money lasts</th>
                    <th scope="col" class="px-4 py-3">Usable wealth left (excl. home)</th>
                    <th scope="col" class="px-4 py-3">Total wealth left (incl. home equity)</th>
                    @if ($showIht)<th scope="col" class="px-4 py-3">Inheritance tax</th>@endif
                    <th scope="col" class="px-4 py-3"><span class="sr-only">Links</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($plans as $plan)
                    <tr class="{{ $plan['isBase'] ? 'bg-blue-50/40' : '' }}">
                        <th scope="row" class="px-4 py-3 text-left font-medium text-gray-900">
                            {{ $plan['name'] }}
                            @if ($plan['isBase'])
                                <span class="ml-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">Base</span>
                            @endif
                            @if ($plan['changes'])
                                <div class="mt-1 flex flex-wrap gap-1 font-normal">
                                    @foreach ($plan['changes'] as $change)
                                        <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                                            {{ $change['label'] }}: {{ $change['to'] }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                            @if ($plan['orphans'] !== [])
                                <span class="mt-1 block text-xs font-normal text-amber-700">Some of this what-if's changes no longer apply because the base plan changed. Re-open it to review.</span>
                            @endif
                        </th>
                        <td class="px-4 py-3 text-gray-700">
                            {{ $plan['variant'] }}
                            @if ($plan['buyShortfall'])
                                <span class="mt-1 block text-xs font-medium text-red-700">⚠ {{ $plan['buyShortfall'] }} of this purchase is unfunded — no documented source covers it, so the plan fails in year one until it is funded.</span>
                            @endif
                            @if ($plan['buyMortgage'])
                                <span class="mt-1 block text-xs text-gray-600">{{ $plan['buyMortgage'] }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-700">{{ $plan['essentialsMet'] ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-3 text-gray-700">
                            @if ($plan['moneyLasts'])
                                Yes, to {{ $plan['finalYear'] }}
                            @else
                                Runs low in {{ $plan['depletionYear'] }}
                            @endif
                        </td>
                        <td class="px-4 py-3 tabular-nums text-gray-900">{{ $plan['usableWealth'] }}</td>
                        <td class="px-4 py-3 tabular-nums text-gray-900">{{ $plan['totalWealth'] }}</td>
                        @if ($showIht)<td class="px-4 py-3 tabular-nums text-gray-900">{{ $plan['ihtDue'] ?? '— not modelled' }}</td>@endif
                        <td class="px-4 py-3 text-right">
                            <a href="{{ $plan['resultsUrl'] }}" class="font-medium text-blue-600 hover:text-blue-700">Results</a>
                            <span class="text-gray-500" aria-hidden="true">·</span>
                            <a href="{{ $plan['editUrl'] }}" class="font-medium text-blue-600 hover:text-blue-700">Edit</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($plans->count() === 1)
        <p class="mt-4 text-sm text-gray-500">This plan has no what-ifs yet. Create one to see its figures beside the base.</p>
    @endif

    <p class="mt-2 text-xs text-gray-500">
        "Money lasts" means the usable money (excluding your home) is not exhausted before the end of the projection.
        Figures are in today's money (real terms).
    </p>

    {{-- Wealth-over-time burndown: each plan as one line, overlaid. --}}
    <section aria-labelledby="burndown-heading" class="mt-8 rounded-lg border border-gray-200 bg-white p-5">
        <h2 id="burndown-heading" class="text-xl font-semibold text-gray-900">Usable wealth over time</h2>
        <p class="mt-1 text-sm text-gray-600">
            Each plan's spendable money (excluding your home) across the central projection, overlaid so you can
            read the trajectories against each other. A line burning down to zero is money running out.
            @if ($burndown['dipsNegative'])
                Where a line continues <strong>below £0</strong> it shows the <strong>cumulative shortfall</strong> — the extra money that plan would need to keep spending at the planned level after its savings are gone.
            @endif
            Figures are in today's money. These are consequences, not a recommendation.
        </p>

        <div class="mt-4" wire:ignore>
            <div x-data="chart(@js($burndown['options']))" role="img"
                aria-label="Line chart of usable wealth (excluding the home) by year for each plan. The full figures are in the data table below."></div>
        </div>

        <details class="mt-4">
            <summary class="cursor-pointer text-sm font-medium text-blue-700">Show the numbers behind this chart</summary>
            <div class="mt-2 overflow-x-auto" tabindex="0">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">Usable wealth (excluding the home) by year for each plan, in today's money.</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="border-b border-gray-200 px-3 py-2 text-left font-medium text-gray-700">Year</th>
                            @foreach ($burndown['rows'] as $row)
                                <th scope="col" class="border-b border-gray-200 px-3 py-2 text-right font-medium text-gray-700">{{ $row['name'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($burndown['years'] as $year)
                            <tr>
                                <th scope="row" class="border-b border-gray-100 px-3 py-2 text-left font-medium text-gray-800">{{ $year }}</th>
                                @foreach ($burndown['rows'] as $row)
                                    <td class="border-b border-gray-100 px-3 py-2 text-right tabular-nums text-gray-800">{{ $row['cells'][$year] ?? '—' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </section>

    {{-- Decision-support Phase 3: the same plans compared on their MONTE CARLO outcome, as plain
         word-band chips over net-position sparklines. Its own surface — deliberately kept apart
         from the deterministic Yes/No table above. Neutral and unordered unless the walled-off
         `interpret` ability is on, in which case the rows come reordered best-first with a "which
         to lean towards" narrative (ordering is advice). --}}
    @php
        $chipStyles = [
            'strong' => ['cls' => 'bg-emerald-100 text-emerald-800', 'icon' => '✓'],
            'good' => ['cls' => 'bg-green-100 text-green-800', 'icon' => '✓'],
            'borderline' => ['cls' => 'bg-amber-100 text-amber-800', 'icon' => '~'],
            'weak' => ['cls' => 'bg-orange-100 text-orange-800', 'icon' => '!'],
            'poor' => ['cls' => 'bg-red-100 text-red-800', 'icon' => '✕'],
        ];
    @endphp
    <section aria-labelledby="combo-heading" class="mt-8 rounded-lg border border-gray-200 bg-white p-5">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 id="combo-heading" class="text-xl font-semibold text-gray-900">Chance the money lasts, across your futures</h2>
                <p class="mt-1 max-w-3xl text-sm text-gray-600">
                    Each plan below is scored on its full Monte&nbsp;Carlo simulation — a plain read of how likely the
                    money is to cover the essentials, over the trend of your spendable position. This is separate from
                    the central-projection table above, which shows a single best estimate.
                </p>
            </div>
            <a href="{{ $combinationCsvUrl }}" class="shrink-0 text-sm text-blue-700 underline">Download CSV</a>
        </div>

        {{-- A factual, guidance-side observation (e.g. a longer life raising the odds because the
             binding risk is survivor income), shown only when something counterintuitive appears. --}}
        @if ($comparison['callout'])
            <p class="mt-4 rounded-md bg-indigo-50 px-3 py-2 text-sm text-indigo-900">{{ $comparison['callout'] }}</p>
        @endif

        {{-- Advice-side ranking narrative, only behind the `interpret` ability. --}}
        @if ($combinationRanked && ! empty($combinationRanking))
            <div class="mt-4">
                @include('livewire.partials.interpretation', ['interpretation' => $combinationRanking])
            </div>
        @endif

        @if ($comparison['anyMissing'])
            <p class="mt-4 rounded-md bg-blue-50 px-3 py-2 text-sm text-blue-800" role="status">
                Some plans have not been simulated yet — use <strong>Re-run all</strong> above to score every plan across its futures.
            </p>
        @endif

        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($comparison['rows'] as $row)
                <div class="rounded-lg border border-gray-200 p-4"
                     wire:key="combo-{{ $loop->index }}-{{ \Illuminate\Support\Str::slug($row['name']) ?: 'plan' }}">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="font-medium text-gray-900">{{ $row['name'] }}</h3>
                        @if ($row['isBase'])
                            <span class="shrink-0 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">Base</span>
                        @endif
                    </div>

                    @if ($row['changes'])
                        <div class="mt-1 flex flex-wrap gap-1">
                            @foreach ($row['changes'] as $change)
                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">{{ $change['label'] }}: {{ $change['to'] }}</span>
                            @endforeach
                        </div>
                    @endif

                    @if ($row['chip'])
                        @php $style = $chipStyles[$row['chip']['level']] ?? $chipStyles['borderline']; @endphp
                        <div class="mt-3">
                            <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-semibold {{ $style['cls'] }}">
                                <span aria-hidden="true">{{ $style['icon'] }}</span> {{ $row['chip']['word'] }}
                            </span>
                        </div>

                        {{-- Net-position sparkline: a glanceable trend; the numbers are in the drill-down + CSV. --}}
                        <div class="mt-3" wire:ignore>
                            <div x-data="chart(@js($row['sparkline']['options']))" role="img"
                                 aria-label="Spendable net position over time for {{ $row['name'] }}.@if ($row['sparkline']['runsShortYear']) It dips below £0 around {{ $row['sparkline']['runsShortYear'] }}.@else It stays above £0 to {{ $row['sparkline']['endYear'] }}.@endif The figures are in the drill-down below."></div>
                        </div>

                        <details class="mt-3">
                            <summary class="cursor-pointer text-sm font-medium text-blue-700">Show the numbers</summary>
                            <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                                <dt class="text-gray-500">Essentials last</dt>
                                <dd class="text-right tabular-nums text-gray-900">{{ $row['figures']['successEssentials'] }}</dd>
                                <dt class="text-gray-500">Full spend lasts</dt>
                                <dd class="text-right tabular-nums text-gray-900">{{ $row['figures']['successFullSpend'] }}</dd>
                                <dt class="text-gray-500">Runs short</dt>
                                <dd class="text-right tabular-nums text-gray-900">{{ $row['figures']['runsShort'] }}@if ($row['figures']['runsShortYear']) (around {{ $row['figures']['runsShortYear'] }})@endif</dd>
                                <dt class="text-gray-500">Usable wealth (p10)</dt>
                                <dd class="text-right tabular-nums text-gray-900">{{ $row['figures']['p10Usable'] ?? '—' }}</dd>
                                <dt class="text-gray-500">Usable wealth (median)</dt>
                                <dd class="text-right tabular-nums text-gray-900">{{ $row['figures']['medianUsable'] ?? '—' }}</dd>
                                <dt class="text-gray-500">Simulated paths</dt>
                                <dd class="text-right tabular-nums text-gray-900">{{ number_format($row['figures']['paths']) }}</dd>
                            </dl>
                        </details>
                    @else
                        <p class="mt-3 rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-500">Not simulated yet. Use “Re-run all” above to score this plan across its futures.</p>
                    @endif

                    <a href="{{ $row['resultsUrl'] }}" class="mt-3 inline-block text-xs font-medium text-blue-600 hover:text-blue-700">Open this plan's results →</a>
                </div>
            @endforeach
        </div>

        <p class="mt-4 text-xs text-gray-500">
            "Chance the money lasts" is the share of simulated futures in which your spendable money covers the
            essentials to the end. Figures are in today's money.
            @unless ($combinationRanked) The plans are shown in your own order, not ranked. @endunless
            Guidance only, not a personal recommendation.
        </p>
    </section>

    <x-sources-and-contacts class="mt-8" :show-mortgage="$sourcesShowMortgage" :show-cgt="$sourcesShowCgt" />

    <div class="mt-6">
        <x-disclaimer.result />
    </div>

    @if (config('assistant.enabled'))
        {{-- Docked assistant in COMPARE mode: it reasons over ALL the compared plans (base + ready
             what-ifs) so it can answer comparison questions. A page-level fixed panel, out of flow. --}}
        <livewire:scenario-assistant :scenario="$base" :compare="true" />
    @endif
</div>
