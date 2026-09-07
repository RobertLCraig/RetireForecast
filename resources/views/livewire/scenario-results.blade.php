@php
    $card = 'rounded-lg border border-gray-200 bg-white p-5';
    $th = 'border-b border-gray-200 px-3 py-2 text-left font-medium text-gray-700';
    $td = 'border-b border-gray-100 px-3 py-2 text-gray-800';

    // The page is grouped into four tabs, so the first screen is the answer rather than a
    // wall of eighteen sections. ONE source: this list says which tab a section sits in,
    // whether it is present this render (the same conditions the sections themselves use),
    // and what the "on this page" nav calls it — grouped by tab, in document order within
    // each. Add a section to the page and its entry here together.
    $sections = [
        ['id' => 'sec-headline', 'tab' => 'verdict', 'label' => 'Will the money last?', 'show' => (bool) $presented],
        ['id' => 'sec-longevity', 'tab' => 'verdict', 'label' => 'How long it may need to last', 'show' => ! empty($presented['longevity'])],
        ['id' => 'sec-care', 'tab' => 'verdict', 'label' => 'Care-cost risk', 'show' => ! empty($presented['careImpact'])],
        ['id' => 'sec-fan', 'tab' => 'verdict', 'label' => 'Outlook over time', 'show' => (bool) $presented],
        ['id' => 'sec-input-notes', 'tab' => 'verdict', 'label' => 'A note on your inputs', 'show' => (bool) $inputNotes],
        ['id' => 'sec-shock', 'tab' => 'verdict', 'label' => 'Pension lump-sum tax shock', 'show' => (bool) $shock],
        ['id' => 'sec-protection', 'tab' => 'verdict', 'label' => 'If one of you died', 'show' => (bool) ($protection ?? null)],
        ['id' => 'sec-capacity', 'tab' => 'verdict', 'label' => 'How much you could lose', 'show' => (bool) ($capacityForLoss ?? null)],
        ['id' => 'sec-explore', 'tab' => 'verdict', 'label' => 'Build a what-if', 'show' => $canMakeWhatIf],
        ['id' => 'sec-how-far', 'tab' => 'verdict', 'label' => 'How far can we go?', 'show' => true],

        ['id' => 'sec-sale', 'tab' => 'money', 'label' => 'If you sell', 'show' => (bool) $saleExplainer],
        ['id' => 'sec-milestones', 'tab' => 'money', 'label' => 'Life events', 'show' => (bool) $milestones],
        ['id' => 'sec-money-over-time', 'tab' => 'money', 'label' => 'Money over time', 'show' => ! empty($timeSeries['income']['rows'])],
        ['id' => 'sec-ladder', 'tab' => 'money', 'label' => 'Year-by-year cashflow', 'show' => ! empty($ladder['rows'])],

        ['id' => 'sec-income-plan', 'tab' => 'spending', 'label' => 'Where your money comes from', 'show' => ! empty($incomePlan['income']) || ! empty($incomePlan['capital'])],
        ['id' => 'sec-budget', 'tab' => 'spending', 'label' => 'Your spending plan', 'show' => ! empty($budget['tiers'])],
        ['id' => 'sec-plsa', 'tab' => 'spending', 'label' => 'PLSA living standards', 'show' => (bool) $plsa],
        ['id' => 'sec-income-floor', 'tab' => 'spending', 'label' => 'Spending vs secure income', 'show' => (bool) $incomeFloor],
        ['id' => 'sec-advice-cost', 'tab' => 'spending', 'label' => 'What advice would cost', 'show' => (bool) ($adviceCost ?? null)],
        ['id' => 'sec-iht', 'tab' => 'spending', 'label' => 'Inheritance tax', 'show' => (bool) ($iht ?? null)],
        ['id' => 'sec-withdrawal-sequencing', 'tab' => 'spending', 'label' => 'How you draw your money', 'show' => (bool) $withdrawal],

        ['id' => 'sec-sensitivity', 'tab' => 'detail', 'label' => 'Assumption sensitivity', 'show' => (bool) $sensitivity],
        ['id' => 'sec-stress', 'tab' => 'detail', 'label' => 'Stress test: past crises', 'show' => (bool) $stressTest],
        ['id' => 'sec-assumptions', 'tab' => 'detail', 'label' => 'Assumptions used', 'show' => true],
        ['id' => 'sec-sources', 'tab' => 'detail', 'label' => 'Check figures & get help', 'show' => true],
    ];

    $tabOf = array_column($sections, 'tab', 'id');

    // A section outside the active tab is still RENDERED, only hidden, so the accessible
    // table and CSV twin of every figure stays in the page. Nothing here needs JavaScript:
    // the tab links are real hrefs the server resolves back into this attribute.
    $panelAttrs = fn (string $t) => new \Illuminate\Support\HtmlString('data-tab="'.$t.'"'.($t === $tab ? '' : ' hidden'));
    $panel = fn (string $id) => $panelAttrs($tabOf[$id]);

    // "On this page" jumps within the tab on display; the tab bar moves between tabs.
    $toc = array_values(array_filter($sections, fn ($s) => $s['show'] && $s['tab'] === $tab));
@endphp

<div class="lg:grid lg:grid-cols-[11rem_minmax(0,1fr)] lg:items-start lg:gap-6">
    {{-- Floating "on this page" nav: jump between sections on a long results page. Real
         anchor links (work without JS); a bundled IntersectionObserver highlights the
         section in view (resources/js/toc.js). Sticky on large screens, hidden on small. --}}
    @if (count($toc) > 1)
        <nav aria-label="On this page" data-results-toc class="sticky top-8 hidden self-start lg:block">
            {{-- gray-600, not gray-400: gray-400 on the gray-50 page is 2.48:1, well under AA. --}}
            <p class="px-3 pb-2 text-xs font-semibold tracking-wide text-gray-600 uppercase">On this page</p>
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
            <a href="{{ route('scenarios.afford', $scenario->baseScenario()) }}" class="text-sm font-semibold text-green-700 underline">What can I afford?</a>
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

    {{-- The tab set. Four groups so the first screen is the answer, not eighteen stacked
         sections. Plain links the server resolves (?tab=…), not a JavaScript widget, and
         marked with aria-current rather than the ARIA tab-widget roles, whose keyboard
         contract a set of links does not honour. --}}
    <nav data-results-tabs aria-label="Results sections" class="border-b border-gray-200">
        <ul class="-mb-px flex flex-wrap gap-x-6">
            @foreach ($tabs as $key => $label)
                <li>
                    <a href="{{ route('scenarios.results', ['scenario' => $scenario, 'tab' => $key]) }}"
                        @if ($key === $tab) aria-current="page" @endif
                        @class([
                            'block border-b-2 px-1 py-3 text-sm font-medium',
                            'border-blue-600 text-blue-700' => $key === $tab,
                            'border-transparent text-gray-600 hover:border-gray-300 hover:text-gray-900' => $key !== $tab,
                        ])>{{ $label }}</a>
                </li>
            @endforeach
        </ul>
    </nav>

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
        <section id="sec-headline" {{ $panel('sec-headline') }} aria-labelledby="headline-heading" class="scroll-mt-6 space-y-3">
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
                    <div class="flex justify-between"><dt class="text-gray-600">Full spending met every year</dt><dd class="font-medium">{{ $v['successFullSpend'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-600">Full spending met in {{ $v['fullSpendMostYearsThreshold'] }}%+ of years</dt><dd class="font-medium">{{ $v['successFullSpendMostYears'] ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-600">Chance of running out</dt><dd class="font-medium">{{ $v['depletionRate'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-600">If so, typically by</dt><dd class="font-medium">{{ $v['medianDepletionYear'] ?? '—' }}</dd></div>
                    @if ($v['usableP50'])
                        <div class="flex justify-between"><dt class="text-gray-600">Usable wealth left (excl. home)</dt><dd class="font-medium">{{ $v['usableP50'] }}</dd></div>
                    @endif
                    <div class="flex justify-between"><dt class="text-gray-600">Total wealth left (incl. home equity)</dt><dd class="font-medium">{{ $v['terminalP50'] }}</dd></div>
                </dl>
            </div>
            <p class="mt-3 text-xs text-gray-500">"Full spending met every year" is all-or-nothing: one year short out of fifty takes it to 0%, so read it beside the {{ $v['fullSpendMostYearsThreshold'] }}%-of-years figure, which says how much of the plan held. A one-off cost with nothing to fund it is judged separately and named in the notes on your inputs, not counted against your year-to-year spending. "Chance of running out" counts the simulated futures with at least one year your essential spending isn't fully covered by income and savings — a shortfall a future may later recover from as guaranteed income catches up. "Wealth left" is the median amount at the very end. So an option can leave money at the end yet still have run short along the way — and "total wealth left" includes the equity in any home you would still own (its value net of any mortgage still owed), which stays high even when the usable cash for day-to-day spending has run out.</p>
        </section>

        {{-- Longevity: how long the money may need to last, read off the joint-life mortality
             sampler the Monte Carlo already runs. Descriptive (a spread of outcomes), not advice. --}}
        @if (! empty($presented['longevity']))
            @php $lg = $presented['longevity']; @endphp
            <section id="sec-longevity" {{ $panel('sec-longevity') }} aria-labelledby="longevity-heading" class="{{ $card }} scroll-mt-6">
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

        {{-- Care-cost risk: shown only when the run modelled it (the builder toggle). --}}
        @if (! empty($presented['careImpact']))
            @php $care = $presented['careImpact']; @endphp
            <section id="sec-care" {{ $panel('sec-care') }} aria-labelledby="care-heading" class="{{ $card }} scroll-mt-6">
                <h2 id="care-heading" class="text-xl font-semibold text-gray-900">The risk of late-life care costs</h2>
                <p class="mt-1 text-sm text-gray-600">These projections include the chance of needing residential or nursing care in later life. Most people pay nothing, but a minority face very large bills — so this is a <strong>fat tail</strong>, shown as a risk rather than a single expected figure.</p>
                <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-md bg-gray-50 p-4">
                        <dt class="text-sm text-gray-500">Chance care costs you something</dt>
                        <dd class="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">{{ $care['sharePct'] }}</dd>
                        <dd class="mt-1 text-xs text-gray-500">of simulated futures included a care spell your household paid towards.</dd>
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <dt class="text-sm text-gray-500">Typical bill, if it happens</dt>
                        <dd class="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">£{{ number_format($care['medianCost']) }}</dd>
                        <dd class="mt-1 text-xs text-gray-500">median your household bears across those futures (today's money), after any local-authority support.</dd>
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <dt class="text-sm text-gray-500">A high-end bill</dt>
                        <dd class="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">£{{ number_format($care['p90Cost']) }}</dd>
                        <dd class="mt-1 text-xs text-gray-500">1 in 10 of the with-care futures cost this much or more.</dd>
                    </div>
                </dl>
                <p class="mt-3 text-xs text-gray-500">Sourced from LaingBuisson self-funder fees (~£1,300–£1,600/week), PSSRU length-of-stay, and the Dilnot Commission ~1-in-4 lifetime risk. Each care year is means-tested under the DHSC charging rules: full fees while the resident's own assets are above £23,250, then a contribution from their income (keeping the Personal Expenses Allowance) with a local authority paying the balance. A partner still living in the home shields it from the assessment.</p>
            </section>
        @endif

        {{-- A run computed before the spendable-money view existed can't show it: say so and
             point at the re-run buttons, rather than silently drawing total wealth as if it
             were spendable money (no silent failure). --}}
        @unless ($presented['usableFanAvailable'])
            <div role="status" {{ $panelAttrs('verdict') }} class="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">
                These results were calculated before the spendable-money (excluding home) view was added, so the charts below show total wealth only and the <strong>Include home value</strong> toggle has nothing to switch to. Run the forecast again (the buttons above) to see your spendable money over time.
            </div>
        @endunless

        {{-- Fan chart: the chosen strategy's outcome spread over time --------------- --}}
        @php $fan = $presented['fan']; @endphp
        <section id="sec-fan" {{ $panel('sec-fan') }} aria-labelledby="fan-heading" class="{{ $card }} scroll-mt-6">
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
                    This is your <strong>spendable</strong> money — it excludes your home, which can't pay day-to-day bills unless you sell.
                    @if ($fan['dipsNegative'])
                        Where a band drops <strong>below £0</strong> those futures have run out of savings; the line keeps falling to show the <strong>cumulative shortfall</strong> — the extra money that future would need to carry on spending at the planned level.
                    @else
                        Watch the lower edge: where the bottom band trends toward £0, a meaningful share of futures have run short.
                    @endif
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
                        aria-label="Fan chart of projected {{ $fan['usableBasis'] ? 'spendable money excluding the home' : 'total wealth including home equity' }} by year for {{ $fan['label'] }}. The full figures are in the data table below."></div>
                </div>
            </div>

            @include('livewire.partials.tail-note')

            <details class="mt-4">
                <summary class="cursor-pointer text-sm font-medium text-blue-700">Show the numbers behind this chart</summary>
                <div class="mt-2 overflow-x-auto" tabindex="0">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Projected {{ $fan['usableBasis'] ? 'spendable money (excl. home)' : 'total wealth (incl. home equity)' }} (real pounds) by calendar year and percentile for {{ $fan['label'] }}</caption>
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
            <div {{ $panelAttrs('verdict') }}>
                @include('livewire.partials.interpretation', ['interpretation' => $interpretation])
            </div>
        @endif
    @endif

    {{-- Input-sanity heads-up: a neutral note where an entered value produced a drastic
         modelling consequence, so a surprising result is understood, not silently wrong.
         Sits under the verdict and the outlook chart, not above them: the reader came for
         the answer, and a caveat read before there is anything to caveat is just noise. --}}
    @if ($inputNotes)
        <div id="sec-input-notes" {{ $panel('sec-input-notes') }} class="scroll-mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4" role="note" aria-label="Notes about your inputs">
            <h2 class="text-sm font-semibold text-amber-900">A note on your inputs</h2>
            <ul class="mt-2 space-y-1 text-sm text-amber-800">
                @foreach ($inputNotes as $note)
                    <li>{{ $note['text'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Care not modelled: a heads-up so a default-off ~1-in-4 six-figure risk isn't a silent
         omission. Shown regardless of run state (it depends on the toggle, not a run); suppressed
         once a run actually models care, when the care panel takes over. --}}
    @if ($careNotModelled)
        <section {{ $panelAttrs('verdict') }} class="{{ $card }} border-amber-200 bg-amber-50" role="note">
            <h2 class="text-lg font-semibold text-amber-900">Later-life care isn't included in this forecast</h2>
            <p class="mt-1 text-sm text-amber-800">These projections don't include the cost of residential or nursing care. It's a real risk: around <strong>1 in 4</strong> people need care in later life, and self-funded fees run to roughly <strong>£1,300–£1,600 a week</strong> (about £65,000–£85,000 a year), which can be a large and prolonged cost. To see how it would affect whether your money lasts, turn on <strong>Model the risk of late-life care costs</strong> in the builder.</p>
        </section>
    @endif

    {{-- "New in this build" review marker: the recent additions are mostly new rows / notes
         inside existing cards, easy to miss — so point to them. Housekeeping, not a figure,
         so it lives in the fine print. Temporary; prune entries as they stop being new (the
         $whatsNew list is built in ScenarioResults::render). --}}
    @if (! empty($whatsNew))
        <div {{ $panelAttrs('detail') }} class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm" aria-label="New in this build">
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
        <div {{ $panelAttrs('detail') }} class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm" aria-label="Since your last run">
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

    {{-- Headline output #1: the lump-sum tax shock. Deterministic, so it shows as soon as
         a withdrawal is planned, before (and independent of) any Monte Carlo run. --}}
    @if ($shock)
        <section id="sec-shock" {{ $panel('sec-shock') }} aria-labelledby="shock-heading" class="{{ $card }} scroll-mt-6 space-y-4">
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
                    Assumes no other employment income that year, as the plan retires the owner by this age. State Pension and any defined-benefit income in payment are modelled in the full forecast, not in this first-withdrawal illustration.
                @endif
            </p>

            <x-signpost />
        </section>
    @endif

    {{-- Compare-assumptions overlay. Deterministic central projection under each sourced
         assumption set, so it shows immediately and illustrates sensitivity, not a ranking. --}}
    @if ($sensitivity)
        <section id="sec-sensitivity" {{ $panel('sec-sensitivity') }} aria-labelledby="sensitivity-heading" class="{{ $card }} scroll-mt-6">
            <h2 id="sensitivity-heading" class="text-xl font-semibold text-gray-900">How sensitive is this to the assumptions?</h2>
            <p class="mt-1 text-sm text-gray-600">
                The central best-estimate projection run under each sourced assumption set. The spread shows how much the answer depends on the assumptions. These are consequences under different assumptions, not a recommendation.
            </p>
            <details class="mt-4">
            <summary class="cursor-pointer text-sm font-medium text-blue-700">Show the figures for each assumption set</summary>
            <div class="mt-2 overflow-x-auto" tabindex="0">
                <table class="w-full text-sm">
                    <caption class="sr-only">Best-estimate outcome under each shipped assumption set</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="{{ $th }}">Assumption set</th>
                            <th scope="col" class="{{ $th }}">Essentials always met</th>
                            <th scope="col" class="{{ $th }}">Full spend always met</th>
                            <th scope="col" class="{{ $th }}">Money runs out</th>
                            <th scope="col" class="{{ $th }}">Total wealth by {{ $sensitivity[0]['finalYear'] }} (incl. home equity)</th>
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
            </details>
            <x-signpost class="mt-4" />
        </section>
    @endif

    {{-- Where the money comes from: the entered income sources and capital pots echoed back,
         and how each source turns on and off across the projection. The counterpart to the
         spending plan below — the spend side has been echoed since Phase C1, the income side
         never was, so a reader could see what a plan spends but not what funds it. --}}
    @if ($incomePlan['income'] || $incomePlan['capital'])
        <section id="sec-income-plan" {{ $panel('sec-income-plan') }} aria-labelledby="income-plan-heading" class="{{ $card }} scroll-mt-6">
            <h2 id="income-plan-heading" class="text-xl font-semibold text-gray-900">Where your money comes from</h2>
            <p class="mt-1 text-sm text-gray-600">
                The income and capital driving this forecast, as entered. Figures are in today's money; the monthly
                figure is the annual one divided by twelve.
            </p>

            @if ($incomePlan['income'])
                <h3 class="mt-4 text-base font-semibold text-gray-900">Income</h3>
                <details class="mt-2">
                <summary class="cursor-pointer text-sm font-medium text-blue-700">Show each income source</summary>
                <div class="mt-2 overflow-x-auto" tabindex="0">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Income sources as entered, with when each starts and ends</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="{{ $th }}">Source</th>
                                <th scope="col" class="{{ $th }}">Whose</th>
                                <th scope="col" class="{{ $th }} text-right">Monthly</th>
                                <th scope="col" class="{{ $th }} text-right">Annual</th>
                                <th scope="col" class="{{ $th }}">Tax</th>
                                <th scope="col" class="{{ $th }}">Starts</th>
                                <th scope="col" class="{{ $th }}">Ends</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($incomePlan['income'] as $row)
                                <tr>
                                    <th scope="row" class="{{ $td }} text-left font-medium">{{ $row['label'] }}</th>
                                    <td class="{{ $td }}">{{ $row['who'] }}</td>
                                    <td class="{{ $td }} text-right tabular-nums">{{ $row['monthly'] }}</td>
                                    <td class="{{ $td }} text-right tabular-nums">{{ $row['annual'] }}</td>
                                    <td class="{{ $td }} text-gray-500">{{ $row['taxable'] ? 'taxable' : 'tax-free' }}</td>
                                    <td class="{{ $td }}">{{ $row['from'] }}</td>
                                    <td class="{{ $td }}">{{ $row['until'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                </details>
                <p class="mt-2 text-xs text-gray-500">These are the amounts as entered today. What the plan actually
                    receives each year — after inflation, retirement, deaths and drawdown — is the year-by-year
                    cashflow and the income chart under <strong>Money over time</strong>.</p>
            @endif

            @if ($incomePlan['capital'])
                <h3 class="mt-5 text-base font-semibold text-gray-900">Capital you can draw on</h3>
                <details class="mt-2">
                <summary class="cursor-pointer text-sm font-medium text-blue-700">Show each capital pot</summary>
                <div class="mt-2 overflow-x-auto" tabindex="0">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Where the household's capital sits, and how each pot is taxed when drawn</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="{{ $th }}">Where it is</th>
                                <th scope="col" class="{{ $th }}">Whose</th>
                                <th scope="col" class="{{ $th }} text-right">Value now</th>
                                <th scope="col" class="{{ $th }}">Paid in</th>
                                <th scope="col" class="{{ $th }}">Available</th>
                                <th scope="col" class="{{ $th }}">How it's taxed on the way out</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($incomePlan['capital'] as $row)
                                <tr>
                                    <th scope="row" class="{{ $td }} text-left font-medium">{{ $row['label'] }}</th>
                                    <td class="{{ $td }}">{{ $row['who'] }}</td>
                                    <td class="{{ $td }} text-right tabular-nums">{{ $row['balance'] }}</td>
                                    <td class="{{ $td }}">{{ $row['paidIn'] }}</td>
                                    <td class="{{ $td }}">{{ $row['access'] }}</td>
                                    <td class="{{ $td }} text-gray-500">{{ $row['tax'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                </details>
            @endif
            @unless ($incomePlan['hasSavings'])
                <p class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800" role="note">No cash, ISA or
                    investment accounts were entered, so this plan starts with <strong>no savings to fall back
                    on</strong>. Any savings shown in later years are surplus income that has accumulated as cash.</p>
            @endunless

            @if ($incomePlan['timeline'])
                <h3 class="mt-5 text-base font-semibold text-gray-900">How each source changes over time</h3>
                <p class="mt-1 text-sm text-gray-600">When each source starts and stops in the projection, and what
                    it pays at each end. Read from the same year-by-year figures as the cashflow table under
                    <strong>Money over time</strong>.</p>
                <details class="mt-2">
                <summary class="cursor-pointer text-sm font-medium text-blue-700">Show each source's first, last and biggest year</summary>
                <div class="mt-2 overflow-x-auto" tabindex="0">
                    <table class="w-full text-sm">
                        <caption class="sr-only">First, last and largest year of each income source in the projection</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="{{ $th }}">Source</th>
                                <th scope="col" class="{{ $th }}">First paid</th>
                                <th scope="col" class="{{ $th }} text-right">Amount then</th>
                                <th scope="col" class="{{ $th }}">Last paid</th>
                                <th scope="col" class="{{ $th }} text-right">Amount then</th>
                                <th scope="col" class="{{ $th }}">Biggest year</th>
                                <th scope="col" class="{{ $th }} text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($incomePlan['timeline'] as $row)
                                <tr>
                                    <th scope="row" class="{{ $td }} text-left font-medium">{{ $row['label'] }}</th>
                                    <td class="{{ $td }}">{{ $row['firstYear'] }}</td>
                                    <td class="{{ $td }} text-right tabular-nums">{{ $row['firstAmount'] }}</td>
                                    <td class="{{ $td }}">
                                        {{ $row['lastYear'] }}
                                        @if ($row['endsBeforeTheEnd'])<span class="text-xs text-gray-500">(stops)</span>@endif
                                    </td>
                                    <td class="{{ $td }} text-right tabular-nums">{{ $row['lastAmount'] }}</td>
                                    <td class="{{ $td }}">{{ $row['peakYear'] }}</td>
                                    <td class="{{ $td }} text-right tabular-nums">{{ $row['peakAmount'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                </details>
            @endif
        </section>
    @endif

    {{-- The 3-tier spending budget echoed back from the inputs (Phase C1). Essential /
         discretionary / self-investment, with saved self-investment shown as building net
         worth rather than counting as spend — reconciles to the forecast's spend. --}}
    @if ($budget['tiers'])
        <section id="sec-budget" {{ $panel('sec-budget') }} aria-labelledby="budget-heading" class="{{ $card }} scroll-mt-6">
            <h2 id="budget-heading" class="text-xl font-semibold text-gray-900">Your spending plan</h2>
            <p class="mt-1 text-sm text-gray-600">
                The annual budget driving this forecast, in three tiers. Self-investment you mark as saved builds your net worth rather than counting as spending. Figures are per year, in today's money.
            </p>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                @foreach ($budget['tiers'] as $tier)
                    <div class="rounded-md border border-gray-200 p-4">
                        <div class="flex items-baseline justify-between">
                            <h3 class="font-medium text-gray-900">{{ $tier['label'] }}</h3>
                            <span class="text-right text-sm font-semibold text-gray-900">
                                {{ $tier['subtotalMonthly'] }}<span class="text-xs font-normal text-gray-500">/mo</span>
                                <span class="block text-xs font-normal text-gray-500">{{ $tier['subtotal'] }}/yr</span>
                            </span>
                        </div>
                        <ul class="mt-2 space-y-1 text-sm text-gray-700">
                            @foreach ($tier['lines'] as $line)
                                <li class="flex justify-between gap-3">
                                    <span>{{ $line['label'] }}@if ($line['saved'])<span class="ml-1 rounded bg-green-100 px-1.5 text-xs text-green-800">saved</span>@endif@if ($line['computed'] ?? false)<span class="ml-1 rounded bg-blue-100 px-1.5 text-xs text-blue-800" title="Worked out from your mortgage terms, not typed in">from your mortgage terms</span>@endif</span>
                                    {{-- Monthly beside annual: a household budgets by the month, and the
                                         annual-only figure made every line an arithmetic exercise. --}}
                                    <span class="shrink-0 text-right tabular-nums">
                                        {{ $line['amountMonthly'] }}<span class="text-xs text-gray-500">/mo</span>
                                        <span class="block text-xs text-gray-500">{{ $line['amount'] }}/yr</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
            <dl class="mt-4 flex flex-wrap gap-x-8 gap-y-1 text-sm">
                <div class="flex gap-2"><dt class="text-gray-600">Total spending</dt><dd class="font-semibold text-gray-900">{{ $budget['spendingTotalMonthly'] }}/mo &middot; {{ $budget['spendingTotal'] }}/yr</dd></div>
                @if ($budget['hasSaving'])
                    <div class="flex gap-2"><dt class="text-gray-600">Saved, builds net worth</dt><dd class="font-semibold text-gray-900">{{ $budget['savingTotalMonthly'] }}/mo &middot; {{ $budget['savingTotal'] }}/yr</dd></div>
                @endif
            </dl>
        </section>
    @endif

    {{-- PLSA Retirement Living Standards benchmark (Phase C4). Where the household's annual
         spending lands against the Minimum / Moderate / Comfortable yardsticks, on the PLSA
         basis (excludes rent/mortgage, includes home running costs). A factual orientation,
         never a recommendation. --}}
    @if ($plsa)
        <section id="sec-plsa" {{ $panel('sec-plsa') }} aria-labelledby="plsa-heading" class="{{ $card }} scroll-mt-6">
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

            <details class="mt-4">
            <summary class="cursor-pointer text-sm font-medium text-blue-700">Show the three standards</summary>
            <div class="mt-2 overflow-x-auto" tabindex="0">
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
            </details>

            @if ($plsa['nextTier'] && $plsa['gapToNext'])
                <p class="mt-3 text-sm text-gray-700">Spending {{ $plsa['gapToNext'] }} a year more would reach the {{ $plsa['nextTierLabel'] }} standard.</p>
            @endif

            <p class="mt-3 text-xs text-gray-500">
                Figures are per year, in today's money, for a {{ $plsa['composition'] }} outside London (the standards publish higher figures for London). The standards assume you own your home outright, so they exclude rent and mortgage payments{{ $plsa['runningCostsIncluded'] ? ', but include your home running costs, which are added here' : '' }}. Source: PLSA Retirement Living Standards, {{ $plsa['edition'] }} ({{ $plsa['source'] }}), figures read {{ $plsa['verifiedOn'] }}.
            </p>
            <x-signpost class="mt-4" />
        </section>
    @endif

    {{-- Income-floor readout (Phase C1): essential spending vs secure (guaranteed-for-life)
         income at the mature point. Neutral — reports the coverage, never whether it is enough. --}}
    @if ($incomeFloor)
        <section id="sec-income-floor" {{ $panel('sec-income-floor') }} aria-labelledby="floor-heading" class="{{ $card }} scroll-mt-6">
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
                <details class="mt-4">
                <summary class="cursor-pointer text-sm font-medium text-blue-700">Show the secure income source by source</summary>
                <div class="mt-2 overflow-x-auto" tabindex="0">
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
                </details>
            @endif

            {{-- Contingent income, reported beside the floor and deliberately outside every total
                 above (board card 0046). Pension Credit is means-tested: it has to be claimed, and
                 it moves with income, capital and a change of circumstances. Counting it in the
                 guaranteed floor said "covered for life" about money that may never arrive. --}}
            @if ($incomeFloor['contingent'])
                <div class="mt-4 rounded-md border border-gray-200 bg-white p-4">
                    <h3 class="text-base font-semibold text-gray-900">Income the forecast counts, but nobody guarantees</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        On top of the secure income above, this year's forecast also spends
                        <strong>{{ $incomeFloor['contingentIncome'] }}</strong> of means-tested help. It is
                        <strong>not part of the secure figure</strong>, because it has to be claimed and it can stop
                        or shrink: it moves with your income, with your savings, with a change of circumstances and
                        with a review.
                    </p>
                    <table class="mt-3 w-full text-sm">
                        <caption class="sr-only">Contingent income by source in {{ $incomeFloor['year'] }}</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="{{ $th }}">Contingent income source</th>
                                <th scope="col" class="{{ $th }} text-right">Per year</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($incomeFloor['contingent'] as $c)
                                <tr>
                                    <th scope="row" class="{{ $td }} text-left font-medium">{{ $c['label'] }}</th>
                                    <td class="{{ $td }} text-right">{{ $c['amount'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <p class="mt-3 text-xs text-gray-500">
                @if ($incomeFloor['fullyCovered'])
                    Essential spending here is fully met by income that does not rely on your savings lasting. Any discretionary spending on top draws on your pots, which the forecast tests.
                @else
                    The rest of essential spending is met by drawing on your savings and pensions, so it depends on those lasting — which is what the forecast tests.
                @endif
            </p>

            {{-- Survivor-cliff dumbbell (Phase 4): the same floor before and after the FIRST death.
                 At the first death a State Pension stops and a DB pension may drop to its survivor
                 rate while essentials fall only part-way, so the survivor's coverage can move
                 sharply — the risk the all-alive floor above hides. Shown only for a couple with a
                 modelled survivor phase. Factual: the coverage before vs after, never a prediction
                 of who dies first, never a recommendation. --}}
            @if ($incomeFloor['survivor'])
                @php $sv = $incomeFloor['survivor']; @endphp
                <div class="mt-5 rounded-md border border-gray-200 bg-white p-4">
                    <h3 class="text-base font-semibold text-gray-900">What happens to this floor at the first death</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        When one of you dies, a State Pension stops and a defined-benefit pension may drop to its survivor rate, while essential spending falls only part-way — so the survivor's secure-income coverage of essentials can change sharply. Here it
                        @if ($incomeFloor['cliff'] > 0)
                            <strong>falls from {{ $incomeFloor['coveragePct'] }}% to {{ $sv['coveragePct'] }}%</strong>.
                        @elseif ($incomeFloor['cliff'] < 0)
                            <strong>rises from {{ $incomeFloor['coveragePct'] }}% to {{ $sv['coveragePct'] }}%</strong>.
                        @else
                            <strong>holds at about {{ $sv['coveragePct'] }}%</strong>.
                        @endif
                    </p>

                    <div class="mt-4 space-y-4">
                        @foreach ([['label' => 'While you are both alive', 'f' => $incomeFloor], ['label' => 'After the first death', 'f' => $sv]] as $phase)
                            @php $f = $phase['f']; $barWidth = max(0, min(100, $f['coveragePct'])); @endphp
                            <div>
                                <div class="flex flex-wrap items-baseline justify-between gap-x-3 text-sm">
                                    <span class="font-medium text-gray-800">{{ $phase['label'] }} ({{ $f['year'] }})</span>
                                    <span class="tabular-nums text-gray-600">secure income {{ $f['secureIncome'] }} of {{ $f['essentialSpend'] }} essentials</span>
                                </div>
                                <div class="mt-1 h-4 w-full overflow-hidden rounded-full bg-gray-200" role="img"
                                    aria-label="{{ $phase['label'] }}, {{ $f['year'] }}: secure income covers {{ $f['coveragePct'] }} percent of essential spending.">
                                    <div class="h-full {{ $f['fullyCovered'] ? 'bg-emerald-500' : 'bg-amber-500' }}" style="width: {{ $barWidth }}%"></div>
                                </div>
                                <p class="mt-1 text-xs {{ $f['fullyCovered'] ? 'text-emerald-700' : 'text-amber-700' }}">
                                    <span aria-hidden="true">{{ $f['fullyCovered'] ? '✓' : '⚠' }}</span> {{ $f['coveragePct'] }}% of essentials covered by secure income{{ $f['fullyCovered'] ? '' : ' — the rest relies on your savings lasting' }}.
                                </p>
                            </div>
                        @endforeach
                    </div>

                    <p class="mt-3 text-xs text-gray-500">
                        "After the first death" is read at {{ $sv['year'] }}, the mature survivor year. This compares the secure-income floor before and after; it is not a prediction of who dies first, and not a recommendation.
                    </p>
                </div>
            @endif

            @if ($pensionCredit)
                <div class="mt-4 rounded-md border border-blue-200 bg-blue-50 p-4 text-sm">
                    <h3 class="font-semibold text-blue-900">How to claim Pension Credit</h3>
                    @if ($pensionCredit['awarded'])
                        <p class="mt-1 text-blue-800">This forecast spends Pension Credit as contingent income, shown separately above rather than as part of your secure income. It's <strong>means-tested, so it has to be claimed</strong> — it isn't paid automatically, and it's one of the most under-claimed benefits, so it's worth acting on.</p>
                    @elseif ($pensionCredit['nearMiss'] !== [])
                        <p class="mt-1 text-blue-800">This forecast awards you <strong>no</strong> Pension Credit, but your income comes close to the line, so it is still worth putting a claim in and letting the DWP decide. We model the Guarantee Credit only, and we apply none of the disregards a real assessment does, so the real gap can be smaller than the one here.</p>
                    @else
                        <p class="mt-1 text-blue-800">This forecast awards you <strong>no</strong> Pension Credit, and the reason is the <strong>mixed-age couple</strong> rule rather than your income.</p>
                    @endif
                    @foreach ($pensionCredit['nearMiss'] as $why)
                        <p class="mt-2 text-xs text-blue-700">{{ $why }}</p>
                    @endforeach
                    @foreach ($pensionCredit['mixedAge'] as $why)
                        <p class="mt-2 text-xs text-blue-700">{{ $why }}</p>
                    @endforeach
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

    {{-- The protection gap (adviser-parity B2): the survivor cliff turned into a number a reader
         can act on. The floor panel above shows that the survivor's coverage falls; this says how
         big the hole is in pounds, what any employer death-in-service cover already fills, and what
         happens on the day that cover CEASES at retirement. Factual and quantum-only: it sizes the
         hole, it does not price or recommend a policy. --}}
    @if ($protection)
        <section id="sec-protection" {{ $panel('sec-protection') }} aria-labelledby="protection-heading" class="{{ $card }} scroll-mt-6">
            <h2 id="protection-heading" class="text-xl font-semibold text-gray-900">If one of you died</h2>
            <p class="mt-1 text-sm text-gray-600">
                A plan for two people quietly assumes you both live roughly as long as the tables say. The first death is the sharpest single change in the whole forecast: one State Pension stops, a work pension may drop to a survivor's rate or stop altogether, and any salary ends — while the spending falls by much less. Below is what a death in <strong>{{ $protection['deathYear'] }}</strong> would do to whoever is left, and how much money would put the plan back where it is now. All figures are in today's money.
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                @foreach ($protection['people'] as $p)
                    <div class="rounded-md border border-gray-200 bg-white p-4">
                        <h3 class="text-base font-semibold text-gray-900">If {{ $p['name'] }} died in {{ $protection['deathYear'] }}</h3>

                        <p class="mt-2 text-sm {{ $p['worseThanBaseline'] ? 'text-amber-800' : 'text-emerald-800' }}">
                            <span aria-hidden="true">{{ $p['worseThanBaseline'] ? '⚠' : '✓' }}</span>
                            @if (! $p['worseThanBaseline'])
                                {{ $p['survivorName'] }} would be no worse off than this plan already is.
                            @elseif ($p['depletionYear'])
                                {{ $p['survivorName'] }} would run short of money in <strong>{{ $p['depletionYear'] }}</strong>{{ $protection['baselineDepletionYear'] ? ', rather than '.$protection['baselineDepletionYear'].' as the plan stands' : '' }}.
                            @else
                                {{ $p['survivorName'] }}'s money would not last as long as it does in this plan.
                            @endif
                        </p>

                        @if ($p['coverInForce']->isPositive())
                            <p class="mt-2 text-sm text-gray-700">
                                {{ $p['name'] }}'s employer cover would pay about <strong>{{ $p['coverInForce']->format() }}</strong>@if ($p['coverDescription']) ({{ $p['coverDescription'] }})@endif. That is already counted in the figures here.
                            </p>
                        @endif

                        <div class="mt-3 rounded-md {{ $p['gap']->isZero() ? 'bg-emerald-50' : 'bg-amber-50' }} p-3">
                            <p class="text-xs {{ $p['gap']->isZero() ? 'text-emerald-800' : 'text-amber-800' }}">
                                {{ $p['gap']->isZero() ? 'Nothing more needed' : 'Life cover that would close the gap' }}
                            </p>
                            <p class="text-lg font-semibold {{ $p['gap']->isZero() ? 'text-emerald-900' : 'text-amber-900' }} tabular-nums">
                                {{ $p['gap']->isZero() ? '—' : $p['gap']->format() }}
                                @if ($p['gapCeilingHit'])
                                    <span class="text-xs font-normal">or more (beyond what this search covers)</span>
                                @endif
                            </p>
                        </div>

                        @if ($p['coverInForce']->isPositive() && $p['needWithoutCover']->pence > $p['gap']->pence)
                            <p class="mt-2 text-xs text-gray-600">
                                Without that employer cover the figure would be about <strong>{{ $p['needWithoutCover']->format() }}</strong> — so the policy {{ $p['name'] }} already has is doing most of the work.
                            </p>
                        @endif

                        @if ($p['gapAfterCoverCeases'] && $p['coverCeasesInYear'])
                            <p class="mt-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-xs text-gray-700">
                                <strong>That cover stops when {{ $p['name'] }} retires in {{ $p['coverCeasesInYear'] }}.</strong>
                                Death-in-service cover only pays while you are still employed. The same death in {{ $p['deathYearAfterRetirement'] }}, a year after retiring, would leave a gap of about <strong>{{ $p['gapAfterCoverCeases']->format() }}</strong> with nothing to meet it.
                            </p>
                        @endif
                    </div>
                @endforeach
            </div>

            <p class="mt-4 text-xs text-gray-500">
                These figures come from the expected path, not an unlucky one, and each is the smallest lump sum that restores the plan to where it stands today — rounded up to the nearest £1,000, because a protection figure rounded down would not quite do the job. It says how large a hole a death would leave; it does not price a policy or say which one to buy, and life cover on someone older or in poor health can be expensive or simply unavailable. A lump sum is only one way to close the hole: less borrowing, more savings or a larger survivor's pension close the same gap, and the "How far can we go?" panel below can put numbers on those. Cover written in trust normally falls outside the estate for Inheritance Tax, and money paid to a survivor counts as capital for means-tested benefits, which can affect Pension Credit.
            </p>

            <x-signpost class="mt-4" />
        </section>
    @endif

    {{-- Capacity for loss (adviser-parity B5): the question an adviser must ask before anyone
         takes investment risk, which a probability cannot answer — how much of a fall could this
         plan actually absorb? Searched, not asserted: the largest across-the-board fall in wealth
         at which the essential floor is still met in every year. --}}
    @if ($capacityForLoss)
        <section id="sec-capacity" {{ $panel('sec-capacity') }} aria-labelledby="capacity-heading" class="{{ $card }} scroll-mt-6">
            <h2 id="capacity-heading" class="text-xl font-semibold text-gray-900">How much could you afford to lose?</h2>
            <p class="mt-1 text-sm text-gray-600">
                This is what advisers call <strong>capacity for loss</strong>. It is not how much risk you would be comfortable taking. It is how far everything you own could fall in value before it stops paying for the things you cannot go without.
            </p>

            @if ($capacityForLoss['alreadyBreached'])
                <div class="mt-4 rounded-md bg-red-50 p-4">
                    <p class="text-sm text-red-900">
                        <span aria-hidden="true">⚠</span>
                        <strong>There is no room to lose anything: this plan is already short.</strong>
                        As it stands, there is at least one year in which this plan cannot pay for the essentials, so the question of how much it could afford to lose does not arise yet. The panels above show when that happens. Everything you own today comes to <strong>{{ $capacityForLoss['wealth']->format() }}</strong> after the mortgage.
                    </p>
                </div>
            @elseif ($capacityForLoss['survivesTotalLoss'])
                <div class="mt-4 rounded-md bg-emerald-50 p-4">
                    <p class="text-sm text-emerald-900">
                        <span aria-hidden="true">✓</span>
                        <strong>Even losing everything would leave your essential spending covered.</strong>
                        Your guaranteed income on its own pays for the essentials in every year of this plan, so there is no fall in the value of your savings, pensions or home that would breach that floor. Your money would buy you far less of everything else, but the floor holds. Everything you own today comes to <strong>{{ $capacityForLoss['wealth']->format() }}</strong> after the mortgage.
                    </p>
                </div>
            @else
                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-md bg-amber-50 p-4">
                        <p class="text-xs text-amber-800">The most your wealth could fall</p>
                        <p class="text-3xl font-semibold text-amber-900 tabular-nums">{{ $capacityForLoss['percent'] }}%</p>
                    </div>
                    <div class="rounded-md border border-gray-200 bg-white p-4">
                        <p class="text-xs text-gray-500">Which is about</p>
                        <p class="text-2xl font-semibold text-gray-900 tabular-nums">{{ $capacityForLoss['cash']->format() }}</p>
                    </div>
                    <div class="rounded-md border border-gray-200 bg-white p-4">
                        <p class="text-xs text-gray-500">Out of everything you own today</p>
                        <p class="text-2xl font-semibold text-gray-900 tabular-nums">{{ $capacityForLoss['wealth']->format() }}</p>
                        <p class="mt-1 text-xs text-gray-500">Savings, pensions and the home, after the mortgage. Worked out from your figures, not entered.</p>
                    </div>
                </div>

                <p class="mt-4 text-sm text-gray-700">
                    Lose up to <strong>{{ $capacityForLoss['percent'] }}%</strong> of that and your essential spending is still paid in every year of this plan. Lose more and it is not.
                </p>
            @endif

            <p class="mt-4 text-xs text-gray-500">
                How this is worked out: everything you own (savings, pensions and the home) is marked down by the same amount on day one, while the mortgage stays exactly where it is, and the plan carries on spending what you entered. Only the essential floor has to hold; the extras would already have gone. It is not a prediction of a crash, and it does not model which of your assets would really fall or by how much: it measures how much room this plan has, and marking everything down together is the cautious way to measure it. The figure comes from the expected path rather than an unlucky one, so a bad run of returns after a fall would use that room up faster. It is rounded down to a whole percent, so it is a level this forecast was actually run at and survived, and it applies to the plan shown above. A different housing choice has a different answer.
            </p>

            <x-signpost class="mt-4" />
        </section>
    @endif

    {{-- What paying for advice would cost this plan (adviser-parity B1): the same projection run
         twice, once with the charges it already bears and once with an adviser's ongoing fee on
         top. A COST comparison, never a verdict on advice — the panel states what it cannot value. --}}
    @if ($adviceCost)
        <section id="sec-advice-cost" {{ $panel('sec-advice-cost') }} aria-labelledby="advice-cost-heading" class="{{ $card }} scroll-mt-6">
            <h2 id="advice-cost-heading" class="text-xl font-semibold text-gray-900">What paying for advice would cost</h2>
            <p class="mt-1 text-sm text-gray-600">
                Your plan already carries <strong>{{ number_format($adviceCost['diyChargePct'], 2) }}% a year</strong> in platform and fund charges. If you also paid an adviser
                {{ $adviceCost['isCustomFee'] ? 'the' : 'the benchmark average' }} <strong>{{ number_format($adviceCost['adviceFeePct'], 2) }}% a year</strong>,
                the money would carry <strong>{{ number_format($adviceCost['advisedChargePct'], 2) }}% a year</strong> instead. Here is what that difference does to this plan, in today's money.
            </p>

            <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                <div class="rounded-md bg-gray-50 p-4">
                    <dt class="text-sm text-gray-500">Charges over the whole plan, as you are now</dt>
                    <dd class="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">{{ $adviceCost['diy']['lifetimeCharges']->format() }}</dd>
                </div>
                <div class="rounded-md bg-gray-50 p-4">
                    <dt class="text-sm text-gray-500">Charges if you were advised</dt>
                    <dd class="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">{{ $adviceCost['advised']['lifetimeCharges']->format() }}</dd>
                </div>
                <div class="rounded-md bg-amber-50 p-4">
                    <dt class="text-sm text-amber-800">The advice itself, over a lifetime</dt>
                    <dd class="mt-1 text-2xl font-semibold text-amber-900 tabular-nums">{{ $adviceCost['extraLifetimeCost']->format() }}</dd>
                </div>
            </dl>

            <p class="mt-4 text-sm text-gray-700">
                It would leave <strong>{{ $adviceCost['terminalWealthLost']->format() }}</strong> less at the end of the plan.
                @if ($adviceCost['diy']['depletionYear'] === null && $adviceCost['advised']['depletionYear'] !== null)
                    And where the money currently lasts, it would instead <strong>run short in {{ $adviceCost['advised']['depletionYear'] }}</strong>.
                @elseif ($adviceCost['yearsOfMoneyLost'] > 0)
                    The money would run short in <strong>{{ $adviceCost['advised']['depletionYear'] }}</strong> rather than {{ $adviceCost['diy']['depletionYear'] }} — {{ $adviceCost['yearsOfMoneyLost'] }} {{ \Illuminate\Support\Str::plural('year', $adviceCost['yearsOfMoneyLost']) }} earlier.
                @elseif ($adviceCost['diy']['depletionYear'] !== null)
                    The year the money runs short ({{ $adviceCost['diy']['depletionYear'] }}) would not move.
                @else
                    The money would still last for life.
                @endif
            </p>

            <div class="mt-4 rounded-md border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                <p><strong>This is a cost, not a verdict.</strong> Advice costing {{ number_format($adviceCost['adviceFeePct'], 2) }}% a year has to add more than {{ number_format($adviceCost['adviceFeePct'], 2) }}% a year of value to be worth paying for — and whether it does is a question this tool cannot answer. The most-cited part of an adviser's value is behavioural (talking someone out of selling in a crash), and none of it is modelled here. Neither is the cost of getting something wrong without one.</p>
            </div>

            <p class="mt-3 text-xs text-gray-500">
                The advised figure is your own charges <em>plus</em> the ongoing fee, and nothing else: the fee is the one figure that can be benchmarked, while how much dearer an advised fund choice is varies too much between firms to assume. A one-off piece of advice (commonly £1,500–£4,000, or a percentage of the amount invested) is charged on top and is not modelled. Fee source: {{ $adviceCost['sourceNote'] }} <a href="{{ $adviceCost['source'] }}" class="underline" rel="noopener">nextwealth.co.uk</a> · checked {{ $adviceCost['verifiedOn'] }}. You can enter a real quote instead, in the builder.
            </p>

            <x-signpost class="mt-4" />
        </section>
    @endif

    {{-- Inheritance tax on the estate at death (only when the IHT toggle is on). Education only:
         the headline nil-rate bands, not a full estate computation. Deterministic; shows pre-run. --}}
    @if ($iht)
        <section id="sec-iht" {{ $panel('sec-iht') }} aria-labelledby="iht-heading" class="{{ $card }} scroll-mt-6">
            <h2 id="iht-heading" class="text-xl font-semibold text-gray-900">Inheritance tax on your estate</h2>
            <p class="mt-1 text-sm text-gray-600">
                @if ($iht['relationship'] === 'married')
                    You're modelled as <strong>married or in a civil partnership</strong>: on the first death everything passes to the survivor free of Inheritance Tax, and both of your allowances are available on the second death.
                @elseif ($iht['relationship'] === 'cohabiting')
                    You're modelled as <strong>cohabiting (not married or in a civil partnership)</strong>: there is <strong>no spouse exemption</strong> on the first death and you cannot share allowances, so the same estate is taxed more heavily than a married couple's.
                @else
                    Inheritance Tax on a single estate: one set of allowances applies.
                @endif
                All figures are in today's money.
            </p>

            <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                <div class="rounded-md bg-gray-50 p-4">
                    <dt class="text-sm text-gray-500">Estate at the final death</dt>
                    <dd class="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">£{{ number_format($iht['secondDeath']['estate']) }}</dd>
                    <dd class="mt-1 text-xs text-gray-500">everything you're modelled to leave (savings, investments, pensions and home).</dd>
                </div>
                <div class="rounded-md bg-gray-50 p-4">
                    <dt class="text-sm text-gray-500">Sheltered by allowances</dt>
                    <dd class="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">£{{ number_format($iht['secondDeath']['nrb'] + $iht['secondDeath']['rnrb']) }}</dd>
                    <dd class="mt-1 text-xs text-gray-500">nil-rate band £{{ number_format($iht['secondDeath']['nrb']) }}{{ $iht['secondDeath']['rnrb'] > 0 ? ' + residence band £'.number_format($iht['secondDeath']['rnrb']) : '' }}.</dd>
                </div>
                <div class="rounded-md bg-gray-50 p-4">
                    <dt class="text-sm text-gray-500">Inheritance Tax due</dt>
                    <dd class="mt-1 text-2xl font-semibold {{ $iht['anyTaxDue'] ? 'text-gray-900' : 'text-green-700' }} tabular-nums">£{{ number_format($iht['total']) }}</dd>
                    <dd class="mt-1 text-xs text-gray-500">
                        @if ($iht['anyTaxDue'])
                            40% on the £{{ number_format($iht['secondDeath']['taxable']) }} above your allowances{{ $iht['firstDeath'] && $iht['firstDeath']['tax'] > 0 ? ' (plus £'.number_format($iht['firstDeath']['tax']).' on the first death)' : '' }}.
                        @else
                            your estate is within the allowances, so no Inheritance Tax is modelled.
                        @endif
                    </dd>
                </div>
            </dl>

            @if ($iht['firstDeath'])
                <p class="mt-3 text-sm text-gray-600">
                    @if ($iht['firstDeath']['spouseExempt'])
                        <strong>First death:</strong> the estate (£{{ number_format($iht['firstDeath']['estate']) }}) passes to the surviving spouse or civil partner with no Inheritance Tax, and their unused allowances carry over to the second death.
                    @else
                        <strong>First death:</strong> the deceased's share of the estate (£{{ number_format($iht['firstDeath']['estate']) }}) passing to a cohabiting partner is a chargeable transfer, so £{{ number_format($iht['firstDeath']['tax']) }} of Inheritance Tax is modelled then.
                    @endif
                </p>
            @endif

            @if ($iht['pensionsIncluded'])
                <p class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800" role="note">Unused pension pots are counted as part of the estate — the rule due from <strong>April 2027</strong> (Finance Act 2026). Before then they sat outside it, so this raises the taxable estate.</p>
            @endif

            {{-- How IHT varies across the simulated futures (once a Monte Carlo run has modelled it):
                 the figures above are a single representative-life estimate; this shows the spread
                 longevity and returns produce. --}}
            @if (! empty($presented['ihtDistribution'] ?? null))
                @php $ihtDist = $presented['ihtDistribution']; @endphp
                <div class="mt-4 rounded-md border border-gray-200 p-4">
                    <h3 class="text-sm font-semibold text-gray-900">How this varies across your simulated futures</h3>
                    <p class="mt-1 text-xs text-gray-500">The figures above assume a single representative lifespan. Across the full simulation, how much Inheritance Tax you leave depends on how long you live and how your investments fare.</p>
                    <dl class="mt-3 grid gap-4 sm:grid-cols-3">
                        <div class="rounded-md bg-gray-50 p-3">
                            <dt class="text-xs text-gray-500">Futures leaving any IHT</dt>
                            <dd class="mt-1 text-xl font-semibold text-gray-900 tabular-nums">{{ $ihtDist['sharePct'] }}</dd>
                        </div>
                        <div class="rounded-md bg-gray-50 p-3">
                            <dt class="text-xs text-gray-500">Typical (median) bill</dt>
                            <dd class="mt-1 text-xl font-semibold text-gray-900 tabular-nums">£{{ number_format($ihtDist['median']) }}</dd>
                        </div>
                        <div class="rounded-md bg-gray-50 p-3">
                            <dt class="text-xs text-gray-500">High end (1 in 10)</dt>
                            <dd class="mt-1 text-xl font-semibold text-gray-900 tabular-nums">£{{ number_format($ihtDist['p90']) }}</dd>
                        </div>
                    </dl>
                </div>
            @endif

            <p class="mt-3 text-xs text-gray-500">This shows the <strong>headline allowances</strong> only (nil-rate band £325,000 and residence nil-rate band up to £175,000 per person, tapered away above a £2m estate), not a full estate calculation: lifetime gifts and the 7-year rule, trusts, business or agricultural relief, and the reduced charity rate are not modelled. Deaths are valued at the plan's representative ages. Verified against gov.uk on 2026-06-27.</p>

            <x-signpost class="mt-3" />
            <p class="mt-2 text-xs text-gray-600">Estate planning is specialised: consider a solicitor or a <a class="underline" href="https://www.step.org/public" rel="noopener">STEP-qualified adviser</a>, and see <a class="underline" href="https://www.gov.uk/inheritance-tax" rel="noopener">gov.uk/inheritance-tax</a>.</p>
        </section>
    @endif

    {{-- How you draw your money: lifetime tax under the current draw order vs filling the
         tax-free bands first. Neutral figures; the directive steer is behind the interpret gate. --}}
    @if ($withdrawal)
        @include('livewire.partials.withdrawal-sequencing')
    @endif

    {{-- Historical stress test: replay real past return + inflation sequences over this exact
         plan, so "will it last" is tested against the worst starts in living memory, not just
         the average. Deterministic; shows before any Monte Carlo run. --}}
    @if ($stressTest)
        <section id="sec-stress" {{ $panel('sec-stress') }} aria-labelledby="stress-heading" class="{{ $card }} scroll-mt-6">
            <h2 id="stress-heading" class="text-xl font-semibold text-gray-900">Stress test: how it would have handled past crises</h2>
            <p class="mt-1 text-sm text-gray-600">We replayed this plan through every year from {{ $stressTest['fromYear'] }} to {{ $stressTest['toYear'] }} as if you had started then, using the <strong>actual</strong> returns and inflation that followed. This is <strong>sequence-of-returns risk</strong>: a bad first decade while you are drawing an income does far more damage than the same slump later.</p>

            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="rounded-md bg-gray-50 p-4">
                    <dt class="text-sm text-gray-500">Historical starts survived</dt>
                    <dd class="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">{{ $stressTest['survivalPct'] }}%</dd>
                    <dd class="mt-1 text-xs text-gray-500">essentials met every year in {{ $stressTest['survivedCount'] }} of {{ $stressTest['tested'] }} historical starting years.</dd>
                </div>
                @if ($stressTest['worst'])
                    <div class="rounded-md bg-gray-50 p-4">
                        <dt class="text-sm text-gray-500">Worst start ({{ $stressTest['worst']['startYear'] }})</dt>
                        @if ($stressTest['worst']['ranOut'])
                            <dd class="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">ran short after {{ $stressTest['worst']['yearsLasted'] }} yrs</dd>
                            <dd class="mt-1 text-xs text-gray-500">the harshest sequence on record for this plan.</dd>
                        @else
                            <dd class="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">£{{ number_format($stressTest['worst']['terminalUsable']) }} left</dd>
                            <dd class="mt-1 text-xs text-gray-500">the least left at the end across every historical start — it still lasted.</dd>
                        @endif
                    </div>
                @endif
            </dl>

            @if ($stressTest['crises'])
                <details class="mt-4">
                <summary class="cursor-pointer text-sm font-medium text-blue-700">Show each crisis it was started into</summary>
                <table class="mt-2 w-full border-collapse text-sm">
                    <caption class="sr-only">Outcome if the plan had started at the onset of each historical crisis</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="{{ $th }}">Retiring into…</th>
                            <th scope="col" class="{{ $th }}">Outcome</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($stressTest['crises'] as $c)
                            <tr>
                                <td class="{{ $td }}">{{ $c['label'] }}</td>
                                <td class="{{ $td }}">
                                    @if ($c['ranOut'])
                                        <span class="font-medium text-red-700">Ran short after {{ $c['yearsLasted'] }} years</span>
                                    @else
                                        <span class="font-medium text-green-700">Lasted</span><span class="text-gray-500"> — £{{ number_format($c['terminalUsable']) }} spendable left at the end</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </details>
            @endif

            <p class="mt-3 text-xs text-gray-500">Real UK asset returns and inflation, 1871–2020, from the Jordà–Schularick–Taylor Macrohistory database (<em>The Rate of Return on Everything</em>). A plan that survives the 1970s and 2008 starts is robust to sequence risk; past performance is not a guarantee of the future.</p>
        </section>
    @endif

    {{-- Show-your-working: the assumptions every figure on this page rests on, surfaced so a
         headline figure can be traced to its basis. Factual, never a recommendation. --}}
    <section id="sec-assumptions" {{ $panel('sec-assumptions') }} aria-labelledby="assumptions-heading" class="{{ $card }} scroll-mt-6">
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
        {{-- Selling costs, moving costs, the buy price and the rent are all sale inputs, so they
             show only for a strategy that sells. A stay-put plan uses none of them. --}}
        @if ($salePlanned && $assumptions['housing'])
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
        <section id="sec-sale" {{ $panel('sec-sale') }} aria-labelledby="sale-heading" class="{{ $card }} scroll-mt-6">
            <h2 id="sale-heading" class="text-xl font-semibold text-gray-900">If you sell: where the money comes from and goes</h2>
            <p class="mt-1 text-sm text-gray-600">
                Selling the current home is assumed at {{ $se['proceeds']['salePrice'] }}. After the costs of selling, this is what is left to invest — and, if buying a cheaper home, what is left over after that purchase. Figures are in today's money.
            </p>

            <details class="mt-4">
            <summary class="cursor-pointer text-sm font-medium text-blue-700">Show how the sale price becomes net proceeds</summary>
            <div class="mt-2 overflow-x-auto" tabindex="0">
                <table class="w-full text-sm">
                    <caption class="sr-only">How the sale price becomes net proceeds</caption>
                    <tbody>
                        <tr><th scope="row" class="{{ $td }} text-left font-medium">Sale price</th><td class="{{ $td }} text-right tabular-nums">{{ $se['proceeds']['salePrice'] }}</td></tr>
                        @if ($se['proceeds']['hasMortgage'])
                            <tr><th scope="row" class="{{ $td }} text-left">less outstanding mortgage</th><td class="{{ $td }} text-right tabular-nums">−{{ $se['proceeds']['mortgage'] }}</td></tr>
                        @endif
                        <tr><th scope="row" class="{{ $td }} text-left">less selling costs{{ $se['sellingCostsAssumed'] ? ' (assumed)' : '' }}</th><td class="{{ $td }} text-right tabular-nums">−{{ $se['proceeds']['sellingCosts'] }}</td></tr>
                        {{-- The breakdown is hidden when the whole figure is the engine's own single
                             assumed line (the total row already says "assumed"), but shown the moment
                             there is more than one line to see: a sale owing capital gains tax has the
                             60-day return charged on top, and that cost must be visible. --}}
                        @if (! $se['sellingCostsAssumed'] || count($se['sellingCostBreakdown']) > 1)
                            @foreach ($se['sellingCostBreakdown'] as $line)
                                <tr class="text-gray-500">
                                    <th scope="row" class="{{ $td }} pl-6 text-left font-normal">
                                        {{ $line['label'] }}
                                        @if ($line['detail']) <span class="text-xs text-gray-400">({{ $line['detail'] }})</span> @endif
                                    </th>
                                    <td class="{{ $td }} text-right text-xs tabular-nums">−{{ $line['value'] }}</td>
                                </tr>
                            @endforeach
                        @endif
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
            </details>
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
                        <h3 class="font-medium text-gray-900">If you sell &amp; buy</h3>
                        <dl class="mt-2 space-y-1 text-sm text-gray-700">
                            <div class="flex justify-between gap-3"><dt>Net proceeds</dt><dd class="tabular-nums">{{ $se['buy']['netProceeds'] }}</dd></div>
                            <div class="flex justify-between gap-3"><dt>less the home bought</dt><dd class="tabular-nums">−{{ $se['buy']['buyPrice'] }}</dd></div>
                            <div class="flex justify-between gap-3"><dt>less stamp duty</dt><dd class="tabular-nums">−{{ $se['buy']['sdlt'] }}</dd></div>
                            <div class="flex justify-between gap-3"><dt>less moving costs</dt><dd class="tabular-nums">−{{ $se['buy']['movingCosts'] }}</dd></div>
                            @if ($se['buy']['fundedFromReceipts'])
                                <div class="flex justify-between gap-3"><dt>plus the capital receipt arriving that year</dt><dd class="tabular-nums">+{{ $se['buy']['fundedFromReceipts'] }}</dd></div>
                            @endif
                            @if ($se['buy']['fundedFromSavings'])
                                <div class="flex justify-between gap-3"><dt>plus from your savings (cash → GIA → ISA)</dt><dd class="tabular-nums">+{{ $se['buy']['fundedFromSavings'] }}</dd></div>
                            @endif
                            @if ($se['buy']['mortgage'])
                                <div class="flex justify-between gap-3"><dt>plus interest-only mortgage{{ $se['buy']['mortgageInterest'] ? ' (~'.$se['buy']['mortgageInterest'].'/yr interest)' : '' }}</dt><dd class="tabular-nums">+{{ $se['buy']['mortgage'] }}</dd></div>
                            @endif
                            @if ($se['buy']['unfundedGap'])
                                <div class="flex justify-between gap-3 font-semibold text-red-700"><dt>Unfunded gap</dt><dd class="tabular-nums">{{ $se['buy']['unfundedGap'] }}</dd></div>
                            @endif
                            <div class="flex justify-between gap-3 border-t border-gray-200 pt-1 font-semibold text-gray-900"><dt>Surplus invested</dt><dd class="tabular-nums">{{ $se['buy']['surplus'] }}</dd></div>
                        </dl>
                        @if ($se['buy']['fundedFromReceipts'])
                            <p class="mt-3 rounded-md bg-blue-50 px-3 py-2 text-sm text-blue-900" role="note">{{ $se['buy']['fundedFromReceipts'] }} of this purchase is paid for by the capital receipt that arrives in the same year, before any savings are drawn and before anything is borrowed. That part of the receipt goes into the home, so the forecast no longer shows it as income that year; anything left over still arrives as normal.</p>
                        @endif
                        @if ($se['buy']['fundedFromSavings'] && $se['buy']['isFullyFunded'])
                            <p class="mt-3 rounded-md bg-blue-50 px-3 py-2 text-sm text-blue-900" role="note">{{ $se['buy']['fundedFromSavings'] }} of this purchase is funded from your savings, drawn cash → GIA → ISA (never pensions). That money leaves the plan on day one{{ $se['buy']['mortgage'] ? ', and the rest of the gap is borrowed' : '' }}.</p>
                        @endif
                        @if ($se['buy']['unfundedGap'])
                            <p class="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-800" role="alert"><strong>{{ $se['buy']['unfundedGap'] }} of this purchase has no funding source.</strong> The sale proceeds{{ $se['buy']['fundedFromSavings'] ? ' and all your savings' : '' }} don't cover it and no mortgage is configured, so the forecast charges the gap as an unmet cost in year one — the plan fails until that money is documented (a mortgage, a receipt, or a cheaper home).</p>
                        @endif
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
        <section id="sec-milestones" {{ $panel('sec-milestones') }} aria-labelledby="milestones-heading" class="{{ $card }} scroll-mt-6">
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

    {{-- The three hero time-series charts (income / wealth / costs over time), each with its
         <details> table twin — the pictures for the same figures the ladder below lists. --}}
    @if (! empty($timeSeries['income']['rows']))
        @include('livewire.partials.time-series-charts')
    @endif

    {{-- Year-by-year cashflow ladder. The deterministic central projection, so it shows
         immediately: where income comes from each year, the tax on it, the spend it must
         meet, and the usable (excl. home) vs total (incl. home equity) wealth carried forward. --}}
    @if ($ladder && $ladder['rows'])
        <section id="sec-ladder" {{ $panel('sec-ladder') }} aria-labelledby="ladder-heading" class="{{ $card }} scroll-mt-6">
            <div class="flex items-center justify-between">
                <h2 id="ladder-heading" class="text-xl font-semibold text-gray-900">
                    Year-by-year cashflow
                    <span class="ml-2 align-middle rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">{{ $ladderSelectedLabel }}</span>
                </h2>
                <button type="button" wire:click="downloadLadderCsv" class="text-sm text-blue-700 underline">Download CSV</button>
            </div>
            <p class="mt-1 text-sm text-gray-600">
                The central best-estimate projection, year by year: where income comes from, the tax on it, the spend it has to meet (split into its essential floor and discretionary remainder), and the usable (excl. home) and total (incl. home equity, net of any mortgage owed) wealth carried forward. Figures are in today's money. This is one illustrative path, not a probability.
            </p>
            <p class="mt-1 text-sm text-gray-600">
                <strong>To spend / month</strong> is what this plan can actually <em>fund</em> that year, divided by twelve —
                not what it targets, so in a year that falls short it shows the smaller, real figure. <strong>free</strong> is
                the part left after essentials: holidays, treats, anything you choose.
                <strong>Available capital</strong> is cash and investments only — money you could spend now without a tax bill
                to get at it. Your pension is listed beneath it because drawing it is taxable, and
                <strong>your home is deliberately excluded</strong>: you can't spend it while you live in it.
                All of these are in <strong>today's money</strong>, so a figure for 2049 is what it would buy at today's prices.
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

            {{-- Would a landlord grant this tenancy? A separate question from whether the money
                 lasts, and one the capital from the sale does not answer, so it gets its own
                 banner rather than a footnote (board card 0031). --}}
            @if ($ladder['rentReferencing'])
                <p class="mt-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="note">
                    <strong>⚠ Renting has to be agreed as well as afforded.</strong>
                    From {{ $ladder['rentReferencing']['firstYear'] }}, and in {{ $ladder['rentReferencing']['years'] }} {{ \Illuminate\Support\Str::plural('year', $ladder['rentReferencing']['years']) }} of this plan, the income would not pass a letting agent's standard reference.
                    {{ $ladder['rentReferencing']['message'] }}
                </p>
            @endif
            @if ($ladder['tenancyUpFront'])
                <p class="mt-3 rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-800" role="note">
                    <strong>Moving in.</strong> {{ $ladder['tenancyUpFront'] }}
                </p>
            @endif
            {{-- What the shortfall MEANS. The table above reports unmet spend as a number of
                 pounds, which reads as belt-tightening whatever bill it is; a missed mortgage
                 instalment ends in possession and a missed council tax bill in a liability order
                 (board card 0052). Framing and signposting, never which bill to pay. --}}
            @if ($ladder['priorityDebt'])
                @php $pd = $ladder['priorityDebt']; @endphp
                <div class="mt-3 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="note">
                    <h3 class="font-semibold">{{ $pd['secured'] ? 'This shortfall is against a debt secured on your home' : 'Not every bill behind this shortfall is the same' }}</h3>
                    <p class="mt-1">{{ $pd['headline'] }}</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach ($pd['points'] as $point)
                            <li>{{ $point }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs">
                        Sources:
                        @foreach ($pd['sources'] as $source)
                            <a href="{{ $source }}" class="underline" rel="noopener">{{ parse_url($source, PHP_URL_HOST) }}</a>@if (! $loop->last) · @endif
                        @endforeach
                    </p>
                </div>
            @endif
            {{-- gray-600, not gray-500: the tinted swatch spans below inherit it, and gray-500 on the
                 red-50 tint is 4.42:1 (under AA). --}}
            <p class="mt-1 text-xs text-gray-600">Rows are tinted: <span class="rounded bg-green-50 px-1">surplus</span> (income covers spend), plain (drawing on savings), <span class="rounded bg-amber-50 px-1">shortfall</span> (spend not fully met), <span class="rounded bg-red-50 px-1">below buffer</span>.</p>
            @if ($ladder['showGrowth'])
                <p class="mt-1 text-xs text-gray-500">Your investments earn in two ways: <strong>Investment income</strong> (interest on cash and dividends from funds) is paid out and taxed each year, so it's part of the income columns; <strong>Investment growth</strong> is the rise in the value of your funds/shares — it stays invested (taxed only as capital gains if you later sell outside an ISA/pension), which is why wealth can grow even in a year you're drawing down.</p>
            @endif
            @if ($ladder['showCharges'])
                <p class="mt-1 text-xs text-gray-500"><strong>Charges:</strong> the investment growth shown is <em>before</em> charges. Platform and fund fees take <strong>{{ $ladder['chargesTotal'] }}</strong> out of the pensions, ISAs and investments over the whole plan (today's money); cash deposits pay none. The yearly rate is in "The assumptions behind these figures" under <strong>The fine print</strong>, and you can change it.</p>
            @endif
            <details class="mt-4">
            <summary class="cursor-pointer text-sm font-medium text-blue-700">Show the year-by-year numbers</summary>
            <div class="mt-2 overflow-x-auto" tabindex="0">
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
                            <th scope="col" class="{{ $th }} text-right">To spend / month</th>
                            @if ($ladder['showGrowth'])
                                <th scope="col" class="{{ $th }} text-right">Investment growth</th>
                            @endif
                            <th scope="col" class="{{ $th }} text-right">Available capital</th>
                            <th scope="col" class="{{ $th }} text-right">Usable (excl. home)</th>
                            <th scope="col" class="{{ $th }} text-right">Total (incl. home equity)</th>
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
                                {{-- What the plan can actually FUND that year, per month — not the
                                     target, which in a short year promises money they don't have. --}}
                                <td class="{{ $td }} text-right font-medium">
                                    {{ $row['monthlyAllowance'] }}
                                    <span class="block text-xs font-normal text-gray-500">ess {{ $row['monthlyEssential'] }} · free {{ $row['monthlyFree'] }}</span>
                                </td>
                                @if ($ladder['showGrowth'])
                                    <td class="{{ $td }} text-right text-gray-600">{{ $row['investmentGrowth'] }}</td>
                                @endif
                                {{-- Cash + investments only: spendable now, no tax to pay to get at
                                     it. The pension is shown beneath because £1 of pension is not
                                     £1 in the hand, and the home is excluded entirely. --}}
                                <td class="{{ $td }} text-right font-medium">
                                    {{ $row['availableCapital'] }}
                                    <span class="block text-xs font-normal text-gray-500">+ {{ $row['pensionCapital'] }} pension (taxable)</span>
                                </td>
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
            </details>
            <x-signpost class="mt-4" />
        </section>
    @endif

    {{-- Build a what-if: set the levers, then SAVE them as a proper what-if scenario (a
         delta-child of the base) — it appears under this plan and is compared on the Compare
         page. Replaces the old throwaway live-slider preview, so a lever change is always a
         real, comparable scenario rather than an unsaved exploration baked into the report. --}}
    @if ($canMakeWhatIf)
        <section id="sec-explore" {{ $panel('sec-explore') }} aria-labelledby="explore-heading" class="{{ $card }} scroll-mt-6">
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

    {{-- "How far can we go?" (decision-support Phase 2): a nested component so its slider drags
         and threshold poll re-render on their own, without re-running this whole results page.
         Renders its own <section id="sec-how-far">. --}}
    {{-- Both of these render their own <section>, so the tab marker goes on a wrapper: a
         nested Livewire component and a Blade component do not share this view's scope. --}}
    <div {{ $panel('sec-how-far') }}>
        <livewire:threshold-explorer :scenario="$scenario" />
    </div>

    <div {{ $panel('sec-sources') }}>
        <x-sources-and-contacts id="sec-sources" class="scroll-mt-6" :show-mortgage="$sourcesShowMortgage" :show-cgt="$sourcesShowCgt" :show-benefits-debt="$sourcesShowBenefitsDebt" />
    </div>

    </div>{{-- /content column --}}

    @if (config('assistant.enabled'))
        {{-- A page-level fixed side panel (not in the content flow); position:fixed floats it
             bottom-right regardless of where it mounts in the grid. --}}
        <livewire:scenario-assistant :scenario="$scenario" />
    @endif
</div>{{-- /on-this-page grid --}}
