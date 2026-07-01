@php
    $card = 'rounded-lg border border-gray-200 bg-white p-5';
    $th = 'border-b border-gray-200 px-3 py-2 text-left font-medium text-gray-700';
    $td = 'border-b border-gray-100 px-3 py-2 text-gray-800';

    // "On this page" floating nav: list only the sections actually present this render
    // (same conditions the sections use below), in document order. One source — add a
    // section to the page and its nav entry here together.
    $toc = array_values(array_filter([
        ['id' => 'sec-input-notes', 'label' => 'A note on your inputs', 'show' => (bool) $inputNotes],
        ['id' => 'sec-headline', 'label' => 'Will the money last?', 'show' => (bool) $presented],
        ['id' => 'sec-longevity', 'label' => 'How long it may need to last', 'show' => ! empty($presented['longevity'])],
        ['id' => 'sec-fan', 'label' => 'Outlook over time', 'show' => (bool) $presented],
        ['id' => 'sec-shock', 'label' => 'Pension lump-sum tax shock', 'show' => (bool) $shock],
        ['id' => 'sec-sensitivity', 'label' => 'Assumption sensitivity', 'show' => (bool) $sensitivity],
        ['id' => 'sec-budget', 'label' => 'Your spending plan', 'show' => ! empty($budget['tiers'])],
        ['id' => 'sec-plsa', 'label' => 'PLSA living standards', 'show' => (bool) $plsa],
        ['id' => 'sec-income-floor', 'label' => 'Spending vs secure income', 'show' => (bool) $incomeFloor],
        ['id' => 'sec-assumptions', 'label' => 'Assumptions used', 'show' => true],
        ['id' => 'sec-sale', 'label' => 'If you sell', 'show' => (bool) $saleExplainer],
        ['id' => 'sec-milestones', 'label' => 'Life events', 'show' => (bool) $milestones],
        ['id' => 'sec-ladder', 'label' => 'Year-by-year cashflow', 'show' => ! empty($ladder['rows'])],
        ['id' => 'sec-explore', 'label' => 'Build a what-if', 'show' => $canMakeWhatIf],
    ], fn ($s) => $s['show']));
@endphp

<div class="lg:grid lg:grid-cols-[13rem_minmax(0,1fr)] lg:items-start lg:gap-8">
    {{-- Floating "on this page" nav: jump between sections on a long results page. Real
         anchor links (work without JS); a bundled IntersectionObserver highlights the
         section in view (resources/js/toc.js). Sticky on large screens, hidden on small. --}}
    @if (count($toc) > 1)
        <nav aria-label="On this page" data-results-toc class="sticky top-8 hidden self-start lg:block">
            <p class="px-3 pb-2 text-xs font-semibold tracking-wide text-gray-400 uppercase">On this page</p>
            <ul class="space-y-0.5 border-l border-gray-200">
                @foreach ($toc as $item)
                    <li>
                        <a href="#{{ $item['id'] }}" data-toc-link="{{ $item['id'] }}"
                            class="-ml-px block border-l-2 border-transparent px-3 py-1.5 text-sm text-gray-600 hover:border-gray-300 hover:text-gray-900">
                            {{ $item['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif

    <div class="min-w-0 space-y-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">{{ $scenario->name }}</h1>
            @if ($whatIf)
                <p class="mt-1 text-sm text-gray-600">
                    A what-if of <a href="{{ $whatIf['baseUrl'] }}" class="font-medium text-blue-700 underline">{{ $whatIf['baseName'] }}</a>
                </p>
            @endif
            <p class="mt-1 text-sm text-gray-600">
                {{ $scenario->householdName() }} · base tax year {{ $scenario->base_tax_year }} ·
                primary option: {{ \App\Forecast\ResultPresenter::variantLabel($scenario->variant) }}
            </p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center justify-end gap-x-4 gap-y-2">
            <a href="{{ route('scenarios.edit', $scenario) }}" class="text-sm text-blue-700 underline">Edit inputs</a>
            <a href="{{ route('scenarios.child', $scenario->baseScenario()) }}" class="text-sm text-blue-700 underline">Create a what-if</a>
            <a href="{{ route('scenarios.compare', $scenario->baseScenario()) }}" class="text-sm text-blue-700 underline">Compare what-ifs</a>
            <a href="{{ route('scenarios.results.pdf', $scenario) }}" class="text-sm text-blue-700 underline">Download PDF summary</a>
            <a href="{{ route('dashboard') }}" class="text-sm text-blue-700 underline">Back to forecasts</a>
        </div>
    </div>

    @if (session('status'))
        <div role="status" class="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>
    @endif

    {{-- One-click what-ifs off the base: a quick way to ask "what if we retire later / live
         longer?" without rebuilding the plan. Each opens the new what-if's results. --}}
    @unless ($whatIf)
        <x-quick-what-ifs :scenario="$scenario" />
    @endunless

    {{-- What this what-if changes vs its base: every overridden input as base → new, so the
         difference is explicit rather than buried in identical-looking inputs. --}}
    @if ($whatIf)
        <section aria-labelledby="whatif-heading" class="rounded-lg border border-amber-200 bg-amber-50 p-5">
            <h2 id="whatif-heading" class="text-sm font-semibold text-amber-900">What this what-if changes</h2>
            @if ($whatIf['changes'])
                <ul class="mt-3 space-y-1.5">
                    @foreach ($whatIf['changes'] as $change)
                        <li class="flex flex-wrap items-baseline gap-x-2 text-sm">
                            <span class="text-gray-700">{{ $change['label'] }}:</span>
                            <span class="text-gray-500 line-through tabular-nums">{{ $change['from'] }}</span>
                            <span aria-hidden="true" class="text-amber-700">→</span>
                            <span class="font-semibold text-amber-900 tabular-nums">{{ $change['to'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-2 text-sm text-amber-900">This what-if currently matches its base (no inputs changed).</p>
            @endif
            @if ($whatIf['orphans'])
                <p class="mt-3 text-xs text-amber-800">
                    Some earlier changes no longer apply because the base was edited since: {{ implode(', ', $whatIf['orphans']) }}. Edit this what-if to refresh them.
                </p>
            @endif
        </section>
    @endif

    {{-- Run controls --------------------------------------------------------------- --}}
    <div class="{{ $card }}">
        <div class="flex flex-wrap items-center gap-3">
            <button type="button" wire:click="preview" wire:loading.attr="disabled" wire:target="preview"
                class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                <span wire:loading.remove wire:target="preview">Run a quick preview</span>
                <span wire:loading wire:target="preview">Running preview…</span>
            </button>
            <button type="button" wire:click="runFull"
                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-100">
                Run the full 10,000-path forecast
            </button>
            <p class="text-xs text-gray-500">A preview is fast and indicative; the full run is more precise and runs in the background.</p>
        </div>

        @if ($run && ! $run->status->isTerminal())
            <div wire:poll.1500ms="refreshRun" class="mt-4">
                <div class="flex items-center justify-between text-sm text-gray-700">
                    <span>{{ ucfirst($run->status->value) }} — {{ $run->progress_pct }}%</span>
                    <button type="button" wire:click="cancel" class="text-red-700 underline">Cancel</button>
                </div>
                <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-200"
                    role="progressbar" aria-valuenow="{{ $run->progress_pct }}" aria-valuemin="0" aria-valuemax="100"
                    aria-label="Forecast progress">
                    <div class="h-full bg-blue-600 transition-all" style="width: {{ $run->progress_pct }}%"></div>
                </div>
                @if ($run->isAwaitingWorker())
                    {{-- The full run is queued to a background worker; with none running it would
                         otherwise sit silently at "Queued — 0%". Explain why, neutrally. --}}
                    <p role="status" class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        Still waiting for a background worker to pick this up. If you're running locally, start one with <code class="font-mono">php artisan queue:work</code>.
                    </p>
                @endif
            </div>
        @elseif ($run && $run->status === \App\Enums\SimulationStatus::Failed)
            <p role="alert" class="mt-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">The run failed: {{ $run->error }}</p>
        @elseif ($run && $run->status === \App\Enums\SimulationStatus::Cancelled)
            <p class="mt-4 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">This run was cancelled. Start another when ready.</p>
        @endif
    </div>

    {{-- Input-sanity heads-up: a neutral note where an entered value produced a drastic
         modelling consequence, so a surprising result is understood, not silently wrong.
         Placed high, before the figures it affects. --}}
    @if ($inputNotes)
        <div id="sec-input-notes" class="scroll-mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4" role="note" aria-label="Notes about your inputs">
            <h2 class="text-sm font-semibold text-amber-900">A note on your inputs</h2>
            <ul class="mt-2 space-y-1 text-sm text-amber-800">
                @foreach ($inputNotes as $note)
                    <li>{{ $note['text'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- "New in this build" review marker: the recent additions are mostly new rows / notes
         inside existing cards, easy to miss — so point to them. Temporary; prune entries as
         they stop being new (the $whatsNew list is built in ScenarioResults::render). --}}
    @if (! empty($whatsNew))
        <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm" aria-label="New in this build">
            <p class="font-semibold text-blue-900">New in this build</p>
            <ul class="mt-2 space-y-1 text-blue-800">
                @foreach ($whatsNew as $item)
                    <li>&bull; {!! $item !!}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- "Since your last run": how the headline figures moved vs the previous completed run.
         The snapshots survive an input edit (which deletes the runs themselves), so this shows
         what a change did, not just Monte-Carlo seed noise on identical inputs. --}}
    @if (! empty($runDiff))
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm" aria-label="Since your last run">
            <p class="font-semibold text-gray-900">Since your last run</p>
            <ul class="mt-2 space-y-1">
                @foreach ($runDiff as $row)
                    <li class="flex flex-wrap items-baseline gap-x-2">
                        <span class="text-gray-700">{{ $row['label'] }}:</span>
                        <span class="text-gray-500 line-through">{{ $row['from'] }}</span>
                        <span aria-hidden="true" class="text-gray-400">&rarr;</span>
                        <span class="font-semibold {{ $row['better'] === true ? 'text-green-700' : ($row['better'] === false ? 'text-red-700' : 'text-gray-900') }}">{{ $row['to'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! $presented)
        <div class="{{ $card }} text-sm text-gray-600">
            <p>No completed run yet. Run a preview to see headline figures, then the full forecast for the precise picture.</p>
        </div>
    @else
        @php $variants = $presented['variants']; $primary = $presented['primary']; @endphp

        {{-- Per-result disclaimer travels with the figures (plan: every Result render). --}}
        <x-disclaimer.result />

        {{-- Every output is labelled with the mode that produced it (DECISIONS 2026-06-25). --}}
        <p class="text-xs text-gray-500">
            Output mode:
            <span class="font-medium text-gray-700">{{ $interpretation ? 'Interpretation (advice-style, enabled for your account)' : 'Neutral guidance' }}</span>
        </p>

        {{-- Headline numbers as text (never only in a chart) ----------------------- --}}
        <section id="sec-headline" aria-labelledby="headline-heading" class="scroll-mt-6 space-y-3">
            <h2 id="headline-heading" class="text-xl font-semibold text-gray-900">Will the money last?</h2>
            <p class="text-sm text-gray-600">
                Under this run's assumptions, across {{ $resultsRun->n_paths }} simulated futures
                ({{ $resultsRun->mode->value }} run, seed {{ $resultsRun->seed }}).
            </p>
            @php
                $v = $variants[$primary];
                $verdictStyle = [
                    'none' => 'bg-green-50 text-green-800',
                    'low' => 'bg-green-50 text-green-800',
                    'medium' => 'bg-amber-50 text-amber-800',
                    'high' => 'bg-red-50 text-red-800',
                ][$v['verdict']['level']];
            @endphp
            {{-- One scenario, one strategy: this report shows the chosen strategy only. Other
                 strategies (buy / rent / let-out) live as separate what-ifs, compared on Compare. --}}
            <div class="{{ $card }}">
                <h3 class="font-semibold text-gray-900">{{ $v['label'] }}</h3>
                <p class="mt-2 rounded-md px-3 py-2 text-sm font-medium {{ $verdictStyle }}" @if ($v['verdict']['level'] === 'high') role="alert" @endif>{{ $v['verdict']['text'] }}</p>
                <dl class="mt-3 grid gap-x-8 gap-y-1 text-sm sm:grid-cols-2">
                    <div class="flex justify-between"><dt class="text-gray-600">Essentials always met</dt><dd class="font-medium">{{ $v['successEssentials'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-600">Full spending met</dt><dd class="font-medium">{{ $v['successFullSpend'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-600">Chance of running out</dt><dd class="font-medium">{{ $v['depletionRate'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-600">If so, typically by</dt><dd class="font-medium">{{ $v['medianDepletionYear'] ?? '—' }}</dd></div>
                    @if ($v['usableP50'])
                        <div class="flex justify-between"><dt class="text-gray-600">Usable wealth left (excl. home)</dt><dd class="font-medium">{{ $v['usableP50'] }}</dd></div>
                    @endif
                    <div class="flex justify-between"><dt class="text-gray-600">Total wealth left (incl. home)</dt><dd class="font-medium">{{ $v['terminalP50'] }}</dd></div>
                </dl>
            </div>
            <p class="mt-3 text-xs text-gray-500">"Chance of running out" counts the simulated futures with at least one year your essential spending isn't fully covered by income and savings — a shortfall a future may later recover from as guaranteed income catches up. "Wealth left" is the median amount at the very end. So an option can leave money at the end yet still have run short along the way — and "total wealth left" includes any home you would still own, which stays high even when the usable cash for day-to-day spending has run out.</p>
        </section>

        {{-- Longevity: how long the money may need to last, read off the joint-life mortality
             sampler the Monte Carlo already runs. Descriptive (a spread of outcomes), not advice. --}}
        @if (! empty($presented['longevity']))
            @php $lg = $presented['longevity']; @endphp
            <section id="sec-longevity" aria-labelledby="longevity-heading" class="{{ $card }} scroll-mt-6">
                <h2 id="longevity-heading" class="text-xl font-semibold text-gray-900">How long the money may need to last</h2>
                <p class="mt-1 text-sm text-gray-600">From the same joint-life mortality model the simulation runs, framed around the <strong>last survivor</strong> (how long the money has to stretch for a couple). A spread of possibilities, not a prediction.</p>
                <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-md bg-gray-50 p-4">
                        <dt class="text-sm text-gray-500">Plan to roughly</dt>
                        <dd class="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">{{ $lg['planYearsP90'] }} years</dd>
                        <dd class="mt-1 text-xs text-gray-500">a prudent horizon (1 in 10 last this long or longer); median is {{ $lg['planYearsP50'] }} years.</dd>
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <dt class="text-sm text-gray-500">Last survivor reaches</dt>
                        <dd class="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">age {{ $lg['ageP50'] }}</dd>
                        <dd class="mt-1 text-xs text-gray-500">typically; a low-to-high range of {{ $lg['ageP10'] }}–{{ $lg['ageP90'] }}.</dd>
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <dt class="text-sm text-gray-500">Chance one of you reaches</dt>
                        <dd class="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">{{ $lg['reaches95'] }} to 95</dd>
                        <dd class="mt-1 text-xs text-gray-500">and {{ $lg['reaches100'] }} to 100 — the tail the median hides.</dd>
                    </div>
                </dl>
            </section>
        @endif

        {{-- A run computed before the spendable-money view existed can't show it: say so and
             point at the re-run buttons, rather than silently drawing total wealth as if it
             were spendable money (no silent failure). --}}
        @unless ($presented['usableFanAvailable'])
            <div role="status" class="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">
                These results were calculated before the spendable-money (excluding home) view was added, so the charts below show total wealth only and the <strong>Include home value</strong> toggle has nothing to switch to. Run the forecast again (the buttons above) to see your spendable money over time.
            </div>
        @endunless

        {{-- Fan chart: the chosen strategy's outcome spread over time --------------- --}}
        @php $fan = $presented['fan']; @endphp
        <section id="sec-fan" aria-labelledby="fan-heading" class="{{ $card }} scroll-mt-6">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="fan-heading" class="text-xl font-semibold text-gray-900">Projected {{ $fan['usableBasis'] ? 'spendable money' : 'total wealth' }} over time — {{ $fan['label'] }}</h2>
                <div class="flex items-center gap-4">
                    <label class="flex items-center gap-2 text-sm text-gray-600">
                        <input type="checkbox" wire:model.live="includeHome" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        Include home value
                    </label>
                    <button type="button" wire:click="downloadFanCsv" class="text-sm text-blue-700 underline">Download CSV</button>
                </div>
            </div>
            <p class="mt-1 text-sm text-gray-600">
                The shaded bands are the range across thousands of simulated futures (10th–90th and 25th–75th percentiles); the solid line is the median, with half of futures above it and half below. Figures are in today's money.
                @if ($fan['usableBasis'])
                    This is your <strong>spendable</strong> money — it excludes your home, which can't pay day-to-day bills unless you sell. Watch the lower edge: where the bottom band trends toward £0, a meaningful share of futures have run short.
                @else
                    This <strong>includes your home's value</strong> — a net-worth view. The home can't cover day-to-day spending unless sold, so the spendable (excl-home) view is the honest "will it last" picture.
                @endif
            </p>

            {{-- Key on the (non-ignored) outer div so a basis toggle replaces the subtree and
                 re-inits the chart with the new options; wire:ignore inside keeps every other
                 poll from disturbing the live canvas. --}}
            <div class="mt-4" wire:key="fan-chart-{{ $includeHome ? 'incl' : 'excl' }}">
                <div wire:ignore>
                    <div x-data="chart(@js($fan['options']))" role="img"
                        aria-label="Fan chart of projected {{ $fan['usableBasis'] ? 'spendable money excluding the home' : 'total wealth including the home' }} by year for {{ $fan['label'] }}. The full figures are in the data table below."></div>
                </div>
            </div>

            @include('livewire.partials.tail-note')

            <details class="mt-4">
                <summary class="cursor-pointer text-sm font-medium text-blue-700">Show the numbers behind this chart</summary>
                <div class="mt-2 overflow-x-auto" tabindex="0">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Projected {{ $fan['usableBasis'] ? 'spendable money (excl. home)' : 'total wealth (incl. home)' }} (real pounds) by calendar year and percentile for {{ $fan['label'] }}</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="{{ $th }}">Year</th>
                                <th scope="col" class="{{ $th }}">Age(s)</th>
                                <th scope="col" class="{{ $th }}">10th</th>
                                <th scope="col" class="{{ $th }}">25th</th>
                                <th scope="col" class="{{ $th }}">Median</th>
                                <th scope="col" class="{{ $th }}">75th</th>
                                <th scope="col" class="{{ $th }}">90th</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($fan['rows'] as $row)
                                <tr>
                                    <th scope="row" class="{{ $td }} font-medium">{{ $row['year'] }}</th>
                                    <td class="{{ $td }}">{{ $row['ages'] ?? '—' }}</td>
                                    <td class="{{ $td }}">{{ $row['p10'] }}</td>
                                    <td class="{{ $td }}">{{ $row['p25'] }}</td>
                                    <td class="{{ $td }}">{{ $row['p50'] }}</td>
                                    <td class="{{ $td }}">{{ $row['p75'] }}</td>
                                    <td class="{{ $td }}">{{ $row['p90'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        </section>


        {{-- Walled-off, admin-granted interpretation. Built only when the gate allows;
             the directive wording lives in App\Compliance\Interpretation, never here. --}}
        @if ($interpretation)
            @include('livewire.partials.interpretation', ['interpretation' => $interpretation])
        @endif
    @endif
    {{-- Headline output #1: the lump-sum tax shock. Deterministic, so it shows as soon as
         a withdrawal is planned, before (and independent of) any Monte Carlo run. --}}
    @if ($shock)
        <section id="sec-shock" aria-labelledby="shock-heading" class="{{ $card }} scroll-mt-6 space-y-4">
            <div>
                <h2 id="shock-heading" class="text-xl font-semibold text-gray-900">The pension lump-sum tax shock</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Your first flexible withdrawal — a {{ $shock['kind'] }} of {{ $shock['gross'] }} by {{ $shock['ownerLabel'] }} at age {{ $shock['atAge'] }}, at {{ $shock['taxYear'] }} rates.
                    @if ($shock['emergencyApplied'])
                        Because it is the first such withdrawal, the provider has to tax it on the emergency (Month-1) basis, which over-deducts up front.
                    @endif
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-md bg-green-50 p-3">
                    <p class="text-xs text-green-800">Tax-free (25%)</p>
                    <p class="text-lg font-semibold text-green-900">{{ $shock['taxFree'] }}</p>
                </div>
                <div class="rounded-md bg-gray-50 p-3">
                    <p class="text-xs text-gray-600">Taxable portion</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $shock['taxable'] }}</p>
                </div>
                <div class="rounded-md bg-amber-50 p-3">
                    <p class="text-xs text-amber-800">Tax taken at source</p>
                    <p class="text-lg font-semibold text-amber-900">{{ $shock['taxAtSource'] }}</p>
                </div>
            </div>

            @if ($shock['hasOverDeduction'])
                <p class="text-sm text-gray-700">
                    That is <strong>{{ $shock['overDeduction'] }}</strong> more than the {{ $shock['marginalTax'] }} actually due at your marginal rate. The excess can be reclaimed from HMRC{{ $shock['reclaimForm'] ? ' using form '.$shock['reclaimForm'] : '' }}, leaving {{ $shock['netReceived'] }} in hand until the refund.
                </p>
            @else
                <p class="text-sm text-gray-700">Tax taken at source matches the {{ $shock['marginalTax'] }} due, leaving {{ $shock['netReceived'] }} in hand; there is nothing to reclaim.</p>
            @endif

            <details>
                <summary class="cursor-pointer text-sm font-medium text-blue-700">Show the full breakdown</summary>
                <div class="mt-2 overflow-x-auto" tabindex="0">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Lump-sum tax-shock breakdown for the first flexible pension withdrawal</caption>
                        <tbody>
                            @foreach ($shock['rows'] as $row)
                                <tr>
                                    <th scope="row" class="{{ $td }} text-left font-medium">{{ $row['label'] }}</th>
                                    <td class="{{ $td }} text-right">{{ $row['value'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>

            @if ($shock['warnings'])
                <ul class="space-y-1 text-xs text-amber-900">
                    @foreach ($shock['warnings'] as $warning)
                        <li class="rounded bg-amber-50 px-3 py-2">{{ $warning }}</li>
                    @endforeach
                </ul>
            @endif

            <p class="text-xs text-gray-500">
                @if ($shock['workingAssumed'])
                    Assumes other taxable income that year of {{ $shock['otherIncome'] }} (the owner's current salary, as they are still working at this age).
                @else
                    Assumes no other employment income that year, as the plan retires the owner by this age. State Pension and any defined-benefit income in payment are modelled in the full forecast below, not in this first-withdrawal illustration.
                @endif
            </p>

            <x-signpost />
        </section>
    @endif

    {{-- Compare-assumptions overlay. Deterministic central projection under each sourced
         assumption set, so it shows immediately and illustrates sensitivity, not a ranking. --}}
    @if ($sensitivity)
        <section id="sec-sensitivity" aria-labelledby="sensitivity-heading" class="{{ $card }} scroll-mt-6">
            <h2 id="sensitivity-heading" class="text-xl font-semibold text-gray-900">How sensitive is this to the assumptions?</h2>
            <p class="mt-1 text-sm text-gray-600">
                The central best-estimate projection run under each sourced assumption set. The spread shows how much the answer depends on the assumptions. These are consequences under different assumptions, not a recommendation.
            </p>
            <div class="mt-4 overflow-x-auto" tabindex="0">
                <table class="w-full text-sm">
                    <caption class="sr-only">Best-estimate outcome under each shipped assumption set</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="{{ $th }}">Assumption set</th>
                            <th scope="col" class="{{ $th }}">Essentials always met</th>
                            <th scope="col" class="{{ $th }}">Full spend always met</th>
                            <th scope="col" class="{{ $th }}">Money runs out</th>
                            <th scope="col" class="{{ $th }}">Total wealth by {{ $sensitivity[0]['finalYear'] }} (incl. home)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sensitivity as $row)
                            <tr>
                                <th scope="row" class="{{ $td }} font-medium">{{ $row['name'] }}</th>
                                <td class="{{ $td }}">{{ $row['essentialsMet'] ? 'Yes' : 'No' }}</td>
                                <td class="{{ $td }}">{{ $row['fullSpendMet'] ? 'Yes' : 'No' }}</td>
                                <td class="{{ $td }}">{{ $row['depletionYear'] ?? '—' }}</td>
                                <td class="{{ $td }}">{{ $row['terminalWealth'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-signpost class="mt-4" />
        </section>
    @endif

    {{-- The 3-tier spending budget echoed back from the inputs (Phase C1). Essential /
         discretionary / self-investment, with saved self-investment shown as building net
         worth rather than counting as spend — reconciles to the forecast's spend. --}}
    @if ($budget['tiers'])
        <section id="sec-budget" aria-labelledby="budget-heading" class="{{ $card }} scroll-mt-6">
            <h2 id="budget-heading" class="text-xl font-semibold text-gray-900">Your spending plan</h2>
            <p class="mt-1 text-sm text-gray-600">
                The annual budget driving this forecast, in three tiers. Self-investment you mark as saved builds your net worth rather than counting as spending. Figures are per year, in today's money.
            </p>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                @foreach ($budget['tiers'] as $tier)
                    <div class="rounded-md border border-gray-200 p-4">
                        <div class="flex items-baseline justify-between">
                            <h3 class="font-medium text-gray-900">{{ $tier['label'] }}</h3>
                            <span class="text-sm font-semibold text-gray-900">{{ $tier['subtotal'] }}</span>
                        </div>
                        <ul class="mt-2 space-y-1 text-sm text-gray-700">
                            @foreach ($tier['lines'] as $line)
                                <li class="flex justify-between gap-3">
                                    <span>{{ $line['label'] }}@if ($line['saved'])<span class="ml-1 rounded bg-green-100 px-1.5 text-xs text-green-800">saved</span>@endif</span>
                                    <span class="tabular-nums">{{ $line['amount'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
            <dl class="mt-4 flex flex-wrap gap-x-8 gap-y-1 text-sm">
                <div class="flex gap-2"><dt class="text-gray-600">Total spending</dt><dd class="font-semibold text-gray-900">{{ $budget['spendingTotal'] }}/yr</dd></div>
                @if ($budget['hasSaving'])
                    <div class="flex gap-2"><dt class="text-gray-600">Saved, builds net worth</dt><dd class="font-semibold text-gray-900">{{ $budget['savingTotal'] }}/yr</dd></div>
                @endif
            </dl>
        </section>
    @endif

    {{-- PLSA Retirement Living Standards benchmark (Phase C4). Where the household's annual
         spending lands against the Minimum / Moderate / Comfortable yardsticks, on the PLSA
         basis (excludes rent/mortgage, includes home running costs). A factual orientation,
         never a recommendation. --}}
    @if ($plsa)
        <section id="sec-plsa" aria-labelledby="plsa-heading" class="{{ $card }} scroll-mt-6">
            <h2 id="plsa-heading" class="text-xl font-semibold text-gray-900">How your spending compares — PLSA Retirement Living Standards</h2>
            <p class="mt-1 text-sm text-gray-600">
                The PLSA Retirement Living Standards describe what three levels of spending — Minimum, Moderate and Comfortable — typically provide in retirement.
                On the same basis the standards use (excluding rent and mortgage, including everyday home running costs), your spending of <strong>{{ $plsa['comparableSpend'] }}</strong> a year for a {{ $plsa['composition'] }}
                @if ($plsa['belowMinimum'])
                    is below the Minimum standard.
                @else
                    reaches the <strong>{{ $plsa['tierReachedLabel'] }}</strong> standard.
                @endif
                These are a general yardstick, not a recommendation.
            </p>

            <div class="mt-4 overflow-x-auto" tabindex="0">
                <table class="w-full text-sm">
                    <caption class="sr-only">PLSA Retirement Living Standards annual budgets for a {{ $plsa['composition'] }}, and whether your spending reaches each</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="{{ $th }}">Standard</th>
                            <th scope="col" class="{{ $th }} text-right">Annual budget</th>
                            <th scope="col" class="{{ $th }}">Your spending reaches it</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($plsa['tiers'] as $tier)
                            <tr @class(['bg-blue-50' => $tier['key'] === $plsa['tierReached']])>
                                <th scope="row" class="{{ $td }} text-left font-medium">{{ $tier['label'] }}@if ($tier['key'] === $plsa['tierReached'])<span class="ml-2 rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-800">your level</span>@endif</th>
                                <td class="{{ $td }} text-right tabular-nums">{{ $tier['amount'] }}</td>
                                <td class="{{ $td }}">{{ $tier['met'] ? 'Yes' : 'No' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($plsa['nextTier'] && $plsa['gapToNext'])
                <p class="mt-3 text-sm text-gray-700">Spending {{ $plsa['gapToNext'] }} a year more would reach the {{ $plsa['nextTierLabel'] }} standard.</p>
            @endif

            <p class="mt-3 text-xs text-gray-500">
                Figures are per year, in today's money, for a {{ $plsa['composition'] }} outside London (the standards publish higher figures for London). The standards assume you own your home outright, so they exclude rent and mortgage payments@if ($plsa['runningCostsIncluded']) but include your home running costs, which are added here@endif. Source: PLSA Retirement Living Standards, {{ $plsa['edition'] }} ({{ $plsa['source'] }}), figures read {{ $plsa['verifiedOn'] }}.
            </p>
            <x-signpost class="mt-4" />
        </section>
    @endif

    {{-- Income-floor readout (Phase C1): essential spending vs secure (guaranteed-for-life)
         income at the mature point. Neutral — reports the coverage, never whether it is enough. --}}
    @if ($incomeFloor)
        <section id="sec-income-floor" aria-labelledby="floor-heading" class="{{ $card }} scroll-mt-6">
            <h2 id="floor-heading" class="text-xl font-semibold text-gray-900">Essential spending vs secure income</h2>
            <p class="mt-1 text-sm text-gray-600">
                In {{ $incomeFloor['year'] }}, when you would be {{ $incomeFloor['ages'] }}, your secure income — guaranteed for life and not dependent on your savings lasting (State Pension, defined-benefit pensions, annuities and any tax-free income) — covers <strong>{{ $incomeFloor['coveragePct'] }}%</strong> of your essential spending (your essential needs, including any rent or home running costs). Figures are per year, in today's money.
            </p>
            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <div class="rounded-md bg-gray-50 p-3">
                    <p class="text-xs text-gray-600">Essential spending</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $incomeFloor['essentialSpend'] }}</p>
                </div>
                <div class="rounded-md bg-blue-50 p-3">
                    <p class="text-xs text-blue-800">Secure income</p>
                    <p class="text-lg font-semibold text-blue-900">{{ $incomeFloor['secureIncome'] }}</p>
                </div>
                @if ($incomeFloor['fullyCovered'])
                    <div class="rounded-md bg-green-50 p-3">
                        <p class="text-xs text-green-800">Secure surplus over essentials</p>
                        <p class="text-lg font-semibold text-green-900">{{ $incomeFloor['surplus'] ?? $incomeFloor['secureIncome'] }}</p>
                    </div>
                @else
                    <div class="rounded-md bg-amber-50 p-3">
                        <p class="text-xs text-amber-800">Met from savings / pension</p>
                        <p class="text-lg font-semibold text-amber-900">{{ $incomeFloor['gap'] }}</p>
                    </div>
                @endif
            </div>
            @if ($incomeFloor['sources'])
                <div class="mt-4 overflow-x-auto" tabindex="0">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Secure income by source in {{ $incomeFloor['year'] }}</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="{{ $th }}">Secure income source</th>
                                <th scope="col" class="{{ $th }} text-right">Per year</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($incomeFloor['sources'] as $s)
                                <tr>
                                    <th scope="row" class="{{ $td }} text-left font-medium">{{ $s['label'] }}</th>
                                    <td class="{{ $td }} text-right">{{ $s['amount'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <p class="mt-3 text-xs text-gray-500">
                @if ($incomeFloor['fullyCovered'])
                    Essential spending here is fully met by income that does not rely on your savings lasting. Any discretionary spending on top draws on your pots, which the forecast below tests.
                @else
                    The rest of essential spending is met by drawing on your savings and pensions, so it depends on those lasting — which is what the forecast below tests.
                @endif
            </p>

            @if ($pensionCredit)
                <div class="mt-4 rounded-md border border-blue-200 bg-blue-50 p-4 text-sm">
                    <h3 class="font-semibold text-blue-900">How to claim your Pension Credit</h3>
                    <p class="mt-1 text-blue-800">This forecast counts Pension Credit in your secure income above. It's <strong>means-tested, so it has to be claimed</strong> — it isn't paid automatically, and it's one of the most under-claimed benefits, so it's worth acting on.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-blue-800">
                        @foreach ($pensionCredit['howToClaim'] as $step)
                            <li>{{ $step }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-blue-800">Even a small award is worth claiming because it can passport you to other help: {{ implode(', ', $pensionCredit['passports']) }}.</p>
                    <p class="mt-2 text-xs text-blue-700"><a href="{{ $pensionCredit['source'] }}" class="underline" rel="noopener">gov.uk/pension-credit</a> · checked {{ $pensionCredit['verifiedOn'] }}. The exact amount is means-tested — only the DWP can confirm what you'd get.</p>
                </div>
            @endif

            <x-signpost class="mt-4" />
        </section>
    @endif

    {{-- Show-your-working: the assumptions every figure on this page rests on, surfaced so a
         headline figure can be traced to its basis. Factual, never a recommendation. --}}
    <section id="sec-assumptions" aria-labelledby="assumptions-heading" class="{{ $card }} scroll-mt-6">
        <h2 id="assumptions-heading" class="text-xl font-semibold text-gray-900">
            The assumptions behind these figures
            @if ($assumptions['customised'])
                <span class="ml-2 align-middle rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">customised</span>
            @endif
        </h2>
        <p class="mt-1 text-sm text-gray-600">
            Every figure on this page rests on these assumptions. Returns and growth are <strong>real</strong> — they are above inflation, so amounts stay in today's money.
            @if ($assumptions['customised'])
                The figures marked <strong>your figure</strong> are ones you set yourself; the rest come from the assumption set.
            @else
                The "How sensitive is this?" section above shows how much the answer changes under different sets.
            @endif
        </p>
        <dl class="mt-4 grid gap-3 sm:grid-cols-2">
            @foreach ($assumptions['economic'] as $row)
                <div class="rounded-md border p-3 {{ $row['edited'] ? 'border-amber-300 bg-amber-50' : 'border-gray-200' }}">
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-sm text-gray-700">{{ $row['label'] }}</dt>
                        <dd class="text-sm font-semibold text-gray-900 tabular-nums">{{ $row['value'] }}</dd>
                    </div>
                    <p class="mt-1 text-xs {{ $row['edited'] ? 'text-amber-800' : 'text-gray-500' }}">{{ $row['edited'] ? 'your figure · ' : '' }}{{ $row['note'] }}</p>
                </div>
            @endforeach
        </dl>
        <p class="mt-3 text-xs text-gray-500">
            Investment growth blends {{ $assumptions['mix'] }}. Assumption set: <strong>{{ $assumptions['setName'] }}{{ $assumptions['customised'] ? ' (customised)' : '' }}</strong>. {{ $assumptions['sourceNote'] }}
        </p>
        @if ($assumptions['housing'])
            <h3 class="mt-5 text-sm font-semibold text-gray-900">Housing-decision inputs</h3>
            <dl class="mt-2 flex flex-wrap gap-x-8 gap-y-1 text-sm">
                @foreach ($assumptions['housing'] as $row)
                    <div class="flex gap-2"><dt class="text-gray-600">{{ $row['label'] }}</dt><dd class="font-medium text-gray-900">{{ $row['value'] }}</dd></div>
                @endforeach
            </dl>
        @endif
    </section>

    {{-- House-sale explainer: the proceeds waterfall (sale − mortgage − selling costs − CGT
         = net) and where the money goes for each option, single-sourced from the engine and
         reconciled. Shows only when a sale is configured. Factual, never a recommendation. --}}
    @if ($saleExplainer)
        @php $se = $saleExplainer; @endphp
        <section id="sec-sale" aria-labelledby="sale-heading" class="{{ $card }} scroll-mt-6">
            <h2 id="sale-heading" class="text-xl font-semibold text-gray-900">If you sell: where the money comes from and goes</h2>
            <p class="mt-1 text-sm text-gray-600">
                Selling the current home is assumed at {{ $se['proceeds']['salePrice'] }}. After the costs of selling, this is what is left to invest — and, if buying a cheaper home, what is left over after that purchase. Figures are in today's money.
            </p>

            <div class="mt-4 overflow-x-auto" tabindex="0">
                <table class="w-full text-sm">
                    <caption class="sr-only">How the sale price becomes net proceeds</caption>
                    <tbody>
                        <tr><th scope="row" class="{{ $td }} text-left font-medium">Sale price</th><td class="{{ $td }} text-right tabular-nums">{{ $se['proceeds']['salePrice'] }}</td></tr>
                        @if ($se['proceeds']['hasMortgage'])
                            <tr><th scope="row" class="{{ $td }} text-left">less outstanding mortgage</th><td class="{{ $td }} text-right tabular-nums">−{{ $se['proceeds']['mortgage'] }}</td></tr>
                        @endif
                        <tr><th scope="row" class="{{ $td }} text-left">less selling costs{{ $se['sellingCostsAssumed'] ? ' (assumed)' : '' }}</th><td class="{{ $td }} text-right tabular-nums">−{{ $se['proceeds']['sellingCosts'] }}</td></tr>
                        @unless ($se['sellingCostsAssumed'])
                            @foreach ($se['sellingCostBreakdown'] as $line)
                                <tr class="text-gray-500">
                                    <th scope="row" class="{{ $td }} pl-6 text-left font-normal">
                                        {{ $line['label'] }}
                                        @if ($line['detail']) <span class="text-xs text-gray-400">({{ $line['detail'] }})</span> @endif
                                    </th>
                                    <td class="{{ $td }} text-right text-xs tabular-nums">−{{ $line['value'] }}</td>
                                </tr>
                            @endforeach
                        @endunless
                        <tr><th scope="row" class="{{ $td }} text-left">less capital gains tax{{ $se['proceeds']['cgtCharged'] ? '' : ' (main home, fully relieved)' }}</th><td class="{{ $td }} text-right tabular-nums">−{{ $se['proceeds']['cgt'] }}</td></tr>
                        @if ($se['cgtDetail'])
                            <tr class="text-gray-500">
                                <th scope="row" colspan="2" class="{{ $td }} pl-6 text-left text-xs font-normal">
                                    Gain {{ $se['cgtDetail']['gain'] }}, less {{ $se['cgtDetail']['relievedGain'] }} private-residence relief = {{ $se['cgtDetail']['chargeableGain'] }} chargeable; less {{ $se['cgtDetail']['allowanceUsed'] }} allowance = {{ $se['cgtDetail']['taxableGain'] }} taxed at {{ $se['cgtDetail']['ratePct'] }}.
                                </th>
                            </tr>
                        @endif
                        <tr class="bg-blue-50"><th scope="row" class="{{ $td }} text-left font-semibold">Net proceeds</th><td class="{{ $td }} text-right font-semibold tabular-nums">{{ $se['proceeds']['netProceeds'] }}</td></tr>
                    </tbody>
                </table>
            </div>
            @unless ($se['proceeds']['clearsCosts'])
                <p role="status" class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">On these figures the sale does not cover the mortgage and selling costs, so there are no net proceeds to invest.</p>
            @endunless

            <div class="mt-4 grid gap-4 @if ($se['buy']) md:grid-cols-2 @endif">
                <div class="rounded-md border border-gray-200 p-4">
                    <h3 class="font-medium text-gray-900">If you sell &amp; rent</h3>
                    <p class="mt-1 text-sm text-gray-700">All {{ $se['rent']['invested'] }} of the net proceeds is invested.@if ($se['rent']['annualRent']) The rent for a home to rent instead — {{ $se['rent']['annualRent'] }} a year, in today's money — is then paid from income. (This is the projected cost of renting after selling, not a cost you pay now.)@endif</p>
                </div>
                @if ($se['buy'])
                    <div class="rounded-md border border-gray-200 p-4">
                        <h3 class="font-medium text-gray-900">If you sell &amp; buy cheaper</h3>
                        <dl class="mt-2 space-y-1 text-sm text-gray-700">
                            <div class="flex justify-between gap-3"><dt>Net proceeds</dt><dd class="tabular-nums">{{ $se['buy']['netProceeds'] }}</dd></div>
                            <div class="flex justify-between gap-3"><dt>less the cheaper home</dt><dd class="tabular-nums">−{{ $se['buy']['buyPrice'] }}</dd></div>
                            <div class="flex justify-between gap-3"><dt>less stamp duty</dt><dd class="tabular-nums">−{{ $se['buy']['sdlt'] }}</dd></div>
                            <div class="flex justify-between gap-3"><dt>less moving costs</dt><dd class="tabular-nums">−{{ $se['buy']['movingCosts'] }}</dd></div>
                            <div class="flex justify-between gap-3 border-t border-gray-200 pt-1 font-semibold text-gray-900"><dt>Surplus invested</dt><dd class="tabular-nums">{{ $se['buy']['surplus'] }}</dd></div>
                        </dl>
                        @unless ($se['buy']['coversPurchase'])
                            <p class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800" role="note">Buying at {{ $se['buy']['buyPrice'] }} needs <strong>{{ $se['buy']['shortfall'] }} more</strong> than this sale frees — it isn't affordable from the sale alone. The forecast caps the surplus at £0 and buys anyway, so you'd need that extra capital from elsewhere.</p>
                        @endunless
                    </div>
                @endif
            </div>

            <p class="mt-4 text-sm text-gray-700">
                Invested money is not left idle: it goes into an investment account growing at the blended real return of <strong>{{ $se['blendedReturnPct'] }}</strong> a year (above inflation). About {{ $se['incomeYieldPct'] }} of the value is paid out each year as taxable income; the rest is capital growth. The year-by-year cashflow below shows how that balance is drawn on.
            </p>
            <x-signpost class="mt-4" />
        </section>
    @endif

    {{-- Life-event milestones: WHEN the major events happen, so the year-by-year cashflow
         below is legible — what drives each step change. Deterministic; read-only facts. --}}
    @if ($milestones)
        @php
            $milestoneDot = [
                'house_sale' => 'bg-purple-500',
                'retirement' => 'bg-amber-400',
                'pension_access' => 'bg-blue-400',
                'state_pension' => 'bg-green-400',
                'death' => 'bg-gray-500',
            ];
        @endphp
        <section id="sec-milestones" aria-labelledby="milestones-heading" class="{{ $card }} scroll-mt-6">
            <h2 id="milestones-heading" class="text-xl font-semibold text-gray-900">When the big events happen</h2>
            <p class="mt-1 text-sm text-gray-600">
                The major life events in this forecast, in order. These drive the step changes in the year-by-year cashflow below — when earnings stop, a pension starts, the home is sold, or the household changes size. Ages are each person's age in that year.
            </p>
            <ul class="mt-4 space-y-2">
                @foreach ($milestones as $m)
                    <li class="flex items-baseline gap-3 text-sm">
                        <span class="w-12 shrink-0 font-semibold tabular-nums text-gray-900">{{ $m['year'] }}</span>
                        <span class="h-2 w-2 shrink-0 self-center rounded-full {{ $milestoneDot[$m['kind']] ?? 'bg-gray-300' }}" aria-hidden="true"></span>
                        <span class="text-gray-700">{{ $m['label'] }}@if ($m['age'] !== null) <span class="text-gray-500">(age {{ $m['age'] }})</span>@endif</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Year-by-year cashflow ladder. The deterministic central projection, so it shows
         immediately: where income comes from each year, the tax on it, the spend it must
         meet, and the usable (excl. home) vs total (incl. home) wealth carried forward. --}}
    @if ($ladder && $ladder['rows'])
        <section id="sec-ladder" aria-labelledby="ladder-heading" class="{{ $card }} scroll-mt-6">
            <div class="flex items-center justify-between">
                <h2 id="ladder-heading" class="text-xl font-semibold text-gray-900">
                    Year-by-year cashflow
                    <span class="ml-2 align-middle rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">{{ $ladderSelectedLabel }}</span>
                </h2>
                <button type="button" wire:click="downloadLadderCsv" class="text-sm text-blue-700 underline">Download CSV</button>
            </div>
            <p class="mt-1 text-sm text-gray-600">
                The central best-estimate projection, year by year: where income comes from, the tax on it, the spend it has to meet (split into its essential floor and discretionary remainder), and the usable (excl. home) and total (incl. home) wealth carried forward. Figures are in today's money. This is one illustrative path, not a probability.
            </p>

            {{-- Safety-floor headline: does usable money stay above the user's buffer, dip below it,
                 or run out entirely? The buffer (months of essentials) is set in the Spending step. --}}
            @if ($ladder['depletionYear'])
                <p class="mt-3 rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm font-medium text-red-900">⚠ On this strategy, usable money runs out in {{ $ladder['depletionYear'] }}.</p>
            @elseif ($ladder['floorBreachYear'])
                <p class="mt-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-900">⚠ Usable money dips below your safety buffer ({{ $ladder['bufferMonths'] }} {{ \Illuminate\Support\Str::plural('month', $ladder['bufferMonths']) }}' essentials) in {{ $ladder['floorBreachYear'] }}, though it does not run out entirely.</p>
            @elseif ($ladder['bufferMonths'] > 0)
                <p class="mt-3 rounded-md border border-green-300 bg-green-50 px-3 py-2 text-sm font-medium text-green-900">✓ Usable money stays above your safety buffer ({{ $ladder['bufferMonths'] }} {{ \Illuminate\Support\Str::plural('month', $ladder['bufferMonths']) }}' essentials) every year.</p>
            @else
                <p class="mt-3 rounded-md border border-green-300 bg-green-50 px-3 py-2 text-sm font-medium text-green-900">✓ Usable money never runs out on this strategy.</p>
            @endif
            <p class="mt-1 text-xs text-gray-500">Rows are tinted: <span class="rounded bg-green-50 px-1">surplus</span> (income covers spend), plain (drawing on savings), <span class="rounded bg-amber-50 px-1">shortfall</span> (spend not fully met), <span class="rounded bg-red-50 px-1">below buffer</span>.</p>
            @if ($ladder['showGrowth'])
                <p class="mt-1 text-xs text-gray-500">Your investments earn in two ways: <strong>Investment income</strong> (interest on cash and dividends from funds) is paid out and taxed each year, so it's part of the income columns; <strong>Investment growth</strong> is the rise in the value of your funds/shares — it stays invested (taxed only as capital gains if you later sell outside an ISA/pension), which is why wealth can grow even in a year you're drawing down.</p>
            @endif
            <div class="mt-4 overflow-x-auto" tabindex="0">
                <table class="w-full text-sm whitespace-nowrap">
                    <caption class="sr-only">Deterministic year-by-year cashflow: income by source, tax, spend and wealth, in real pounds</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="{{ $th }}">Year</th>
                            <th scope="col" class="{{ $th }}">Age(s)</th>
                            @foreach ($ladder['sources'] as $source)
                                <th scope="col" class="{{ $th }} text-right">{{ $ladder['sourceLabels'][$source] }}</th>
                            @endforeach
                            <th scope="col" class="{{ $th }} text-right">Tax</th>
                            <th scope="col" class="{{ $th }} text-right">Spend</th>
                            @if ($ladder['showGrowth'])
                                <th scope="col" class="{{ $th }} text-right">Investment growth</th>
                            @endif
                            <th scope="col" class="{{ $th }} text-right">Usable (excl. home)</th>
                            <th scope="col" class="{{ $th }} text-right">Total (incl. home)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ladder['rows'] as $row)
                            <tr @class([
                                'bg-red-50' => $row['belowFloor'],
                                'bg-amber-50' => $row['shortfall'] && ! $row['belowFloor'],
                                'bg-green-50' => $row['status'] === 'surplus' && ! $row['belowFloor'],
                            ])>
                                <th scope="row" class="{{ $td }} font-medium">{{ $row['year'] }}</th>
                                <td class="{{ $td }}">{{ $row['ages'] }}</td>
                                @foreach ($ladder['sources'] as $source)
                                    <td class="{{ $td }} text-right">{{ $row['income'][$source] }}</td>
                                @endforeach
                                <td class="{{ $td }} text-right">{{ $row['tax'] }}</td>
                                <td class="{{ $td }} text-right">
                                    {{ $row['spend'] }}
                                    <span class="block text-xs text-gray-500">ess {{ $row['essentialSpend'] }} · disc {{ $row['discretionarySpend'] }}</span>
                                    @if ($row['shortfall'])<span class="block text-xs text-amber-700">unmet {{ $row['shortfall'] }}</span>@endif
                                </td>
                                @if ($ladder['showGrowth'])
                                    <td class="{{ $td }} text-right text-gray-600">{{ $row['investmentGrowth'] }}</td>
                                @endif
                                <td class="{{ $td }} text-right">
                                    {{ $row['usableWealth'] }}
                                    @if ($row['belowFloor'])<span class="block text-xs font-medium text-red-700">below buffer</span>@endif
                                </td>
                                <td class="{{ $td }} text-right">{{ $row['totalWealth'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-signpost class="mt-4" />
        </section>
    @endif

    {{-- Build a what-if: set the levers, then SAVE them as a proper what-if scenario (a
         delta-child of the base) — it appears under this plan and is compared on the Compare
         page. Replaces the old throwaway live-slider preview, so a lever change is always a
         real, comparable scenario rather than an unsaved exploration baked into the report. --}}
    @if ($canMakeWhatIf)
        <section id="sec-explore" aria-labelledby="explore-heading" class="{{ $card }} scroll-mt-6">
            <div class="flex items-center justify-between">
                <h2 id="explore-heading" class="text-xl font-semibold text-gray-900">Build a what-if</h2>
                <button type="button" wire:click="resetSliders" class="text-sm text-blue-700 underline">Reset</button>
            </div>
            <p class="mt-1 text-sm text-gray-600">Set the levers, then save them as a what-if scenario to compare against this plan.</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Retire: {{ $slideRetire === 0 ? 'as planned' : ($slideRetire > 0 ? $slideRetire.' yr later' : abs($slideRetire).' yr earlier') }}</span>
                    <input type="range" min="-5" max="10" step="1" wire:model.live="slideRetire" class="mt-1 w-full">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Spend: {{ $slideSpend === 0 ? 'as planned' : ($slideSpend > 0 ? '+'.$slideSpend.'%' : $slideSpend.'%') }}</span>
                    <input type="range" min="-30" max="30" step="5" wire:model.live="slideSpend" class="mt-1 w-full">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Investment return: {{ $slideReturn === 0 ? 'as assumed' : ($slideReturn > 0 ? '+'.$slideReturn.' pts' : $slideReturn.' pts') }}</span>
                    <input type="range" min="-3" max="3" step="1" wire:model.live="slideReturn" class="mt-1 w-full">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Live: {{ $slideLongevity === 0 ? 'as modelled' : ($slideLongevity > 0 ? $slideLongevity.' yr longer' : abs($slideLongevity).' yr shorter') }}</span>
                    <input type="range" min="-10" max="15" step="1" wire:model.live="slideLongevity" class="mt-1 w-full">
                </label>
            </div>
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-gray-600" aria-live="polite">{{ $sliderSummary }}</p>
                <button type="button" wire:click="makeWhatIf" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Create this what-if</button>
            </div>
            <p class="mt-2 text-xs text-gray-500">Saved as a separate scenario; this report is unchanged. Compare them on the Compare page.</p>
        </section>
    @endif

    </div>{{-- /content column --}}
</div>{{-- /on-this-page grid --}}
