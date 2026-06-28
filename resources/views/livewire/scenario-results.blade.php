@php
    $card = 'rounded-lg border border-gray-200 bg-white p-5';
    $th = 'border-b border-gray-200 px-3 py-2 text-left font-medium text-gray-700';
    $td = 'border-b border-gray-100 px-3 py-2 text-gray-800';
@endphp

<div class="space-y-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">{{ $scenario->name }}</h1>
            <p class="mt-1 text-sm text-gray-600">
                {{ $scenario->householdName() }} · base tax year {{ $scenario->base_tax_year }} ·
                primary option: {{ \App\Forecast\ResultPresenter::variantLabel($scenario->variant) }}
            </p>
        </div>
        <div class="flex shrink-0 items-center gap-4">
            <a href="{{ route('scenarios.edit', $scenario) }}" class="text-sm text-blue-700 underline">Edit inputs</a>
            <a href="{{ route('scenarios.child', $scenario->baseScenario()) }}" class="text-sm text-blue-700 underline">Create a what-if</a>
            <a href="{{ route('scenarios.compare', $scenario->baseScenario()) }}" class="text-sm text-blue-700 underline">Compare what-ifs</a>
            <a href="{{ route('scenarios.results.pdf', $scenario) }}" class="text-sm text-blue-700 underline">Download PDF summary</a>
            <a href="{{ route('dashboard') }}" class="text-sm text-blue-700 underline">Back to forecasts</a>
        </div>
    </div>

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
            </div>
        @elseif ($run && $run->status === \App\Enums\SimulationStatus::Failed)
            <p role="alert" class="mt-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">The run failed: {{ $run->error }}</p>
        @elseif ($run && $run->status === \App\Enums\SimulationStatus::Cancelled)
            <p class="mt-4 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">This run was cancelled. Start another when ready.</p>
        @endif
    </div>

    {{-- Headline output #1: the lump-sum tax shock. Deterministic, so it shows as soon as
         a withdrawal is planned, before (and independent of) any Monte Carlo run. --}}
    @if ($shock)
        <section aria-labelledby="shock-heading" class="{{ $card }} space-y-4">
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
                <div class="mt-2 overflow-x-auto">
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
        <section aria-labelledby="sensitivity-heading" class="{{ $card }}">
            <h2 id="sensitivity-heading" class="text-xl font-semibold text-gray-900">How sensitive is this to the assumptions?</h2>
            <p class="mt-1 text-sm text-gray-600">
                The central best-estimate projection run under each sourced assumption set. The spread shows how much the answer depends on the assumptions. These are consequences under different assumptions, not a recommendation.
            </p>
            <div class="mt-4 overflow-x-auto">
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
        <section aria-labelledby="budget-heading" class="{{ $card }}">
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
        <section aria-labelledby="plsa-heading" class="{{ $card }}">
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

            <div class="mt-4 overflow-x-auto">
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
        <section aria-labelledby="floor-heading" class="{{ $card }}">
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
                <div class="mt-4 overflow-x-auto">
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
            <x-signpost class="mt-4" />
        </section>
    @endif

    {{-- Year-by-year cashflow ladder. The deterministic central projection, so it shows
         immediately: where income comes from each year, the tax on it, the spend it must
         meet, and the usable (excl. home) vs total (incl. home) wealth carried forward. --}}
    @if ($ladder && $ladder['rows'])
        <section aria-labelledby="ladder-heading" class="{{ $card }}">
            <div class="flex items-center justify-between">
                <h2 id="ladder-heading" class="text-xl font-semibold text-gray-900">Year-by-year cashflow</h2>
                <button type="button" wire:click="downloadLadderCsv" class="text-sm text-blue-700 underline">Download CSV</button>
            </div>
            <p class="mt-1 text-sm text-gray-600">
                The central best-estimate projection, year by year: where income comes from, the tax on it, the spend it has to meet, and the usable (excl. home) and total (incl. home) wealth carried forward. Figures are in today's money. This is one illustrative path, not a probability.
            </p>
            <div class="mt-4 overflow-x-auto">
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
                            <th scope="col" class="{{ $th }} text-right">Usable (excl. home)</th>
                            <th scope="col" class="{{ $th }} text-right">Total (incl. home)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ladder['rows'] as $row)
                            <tr @class(['bg-amber-50' => $row['shortfall']])>
                                <th scope="row" class="{{ $td }} font-medium">{{ $row['year'] }}</th>
                                <td class="{{ $td }}">{{ $row['ages'] }}</td>
                                @foreach ($ladder['sources'] as $source)
                                    <td class="{{ $td }} text-right">{{ $row['income'][$source] }}</td>
                                @endforeach
                                <td class="{{ $td }} text-right">{{ $row['tax'] }}</td>
                                <td class="{{ $td }} text-right">
                                    {{ $row['spend'] }}
                                    @if ($row['shortfall'])<span class="block text-xs text-amber-700">unmet {{ $row['shortfall'] }}</span>@endif
                                </td>
                                <td class="{{ $td }} text-right">{{ $row['usableWealth'] }}</td>
                                <td class="{{ $td }} text-right">{{ $row['totalWealth'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-signpost class="mt-4" />
        </section>
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
        <section aria-labelledby="headline-heading" class="space-y-3">
            <h2 id="headline-heading" class="text-xl font-semibold text-gray-900">Will the money last?</h2>
            <p class="text-sm text-gray-600">
                Under this run's assumptions, across {{ $run->n_paths }} simulated futures
                ({{ $run->mode->value }} run, seed {{ $run->seed }}).
            </p>
            <div class="grid gap-4 md:grid-cols-3">
                @foreach ($variants as $key => $v)
                    <div class="{{ $card }} {{ $key === $primary ? 'ring-2 ring-blue-500' : '' }}">
                        <h3 class="font-semibold text-gray-900">{{ $v['label'] }}@if ($key === $primary)<span class="ml-2 rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-800">primary</span>@endif</h3>
                        <dl class="mt-3 space-y-1 text-sm">
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
                @endforeach
            </div>
            <p class="mt-3 text-xs text-gray-500">"Total wealth left" includes any home you would still own, so it can stay high even when the usable cash to meet day-to-day spending has run out — which is why an option can show a large figure here yet still have a high chance of running out.</p>
        </section>

        {{-- Fan chart ------------------------------------------------------------- --}}
        @php $fan = $presented['fan']; @endphp
        <section aria-labelledby="fan-heading" class="{{ $card }}">
            <div class="flex items-center justify-between">
                <h2 id="fan-heading" class="text-xl font-semibold text-gray-900">Projected wealth over time — {{ $fan['label'] }}</h2>
                <button type="button" wire:click="downloadFanCsv" class="text-sm text-blue-700 underline">Download CSV</button>
            </div>
            <p class="mt-1 text-sm text-gray-600">The shaded bands show the spread across simulations (10th–90th and 25th–75th percentiles); the solid line is the median. Figures are in today's money.</p>

            <div class="mt-4" wire:ignore>
                <div x-data="chart(@js($fan['options']))" role="img"
                    aria-label="Fan chart of projected total wealth by year for {{ $fan['label'] }}. The full figures are in the data table below."></div>
            </div>

            <details class="mt-4">
                <summary class="cursor-pointer text-sm font-medium text-blue-700">Show the numbers behind this chart</summary>
                <div class="mt-2 overflow-x-auto">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Projected total wealth (real pounds) by calendar year and percentile for {{ $fan['label'] }}</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="{{ $th }}">Year</th>
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

        {{-- Buy-vs-rent comparison ------------------------------------------------ --}}
        @php $comparison = $presented['comparison']; @endphp
        <section aria-labelledby="compare-heading" class="{{ $card }}">
            <h2 id="compare-heading" class="text-xl font-semibold text-gray-900">Comparing the housing options</h2>
            <p class="mt-1 text-sm text-gray-600">Each option is run on identical simulated futures, so the difference is the housing choice alone. These are consequences, not a recommendation.</p>

            <div class="mt-4" wire:ignore>
                <div x-data="chart(@js($comparison['options']))" role="img"
                    aria-label="Bar chart of total wealth left, including any home still owned, by housing option. The full figures are in the data table below."></div>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">Success probabilities, chance of running out and total wealth left (incl. home) by housing option</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="{{ $th }}">Option</th>
                            <th scope="col" class="{{ $th }}">Essentials met</th>
                            <th scope="col" class="{{ $th }}">Full spend met</th>
                            <th scope="col" class="{{ $th }}">Runs out</th>
                            <th scope="col" class="{{ $th }}">Usable wealth left (excl. home)</th>
                            <th scope="col" class="{{ $th }}">Total wealth left (incl. home)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($comparison['rows'] as $row)
                            <tr>
                                <th scope="row" class="{{ $td }} font-medium">{{ $row['label'] }}</th>
                                <td class="{{ $td }}">{{ $row['successEssentials'] }}</td>
                                <td class="{{ $td }}">{{ $row['successFullSpend'] }}</td>
                                <td class="{{ $td }}">{{ $row['depletionRate'] }}</td>
                                <td class="{{ $td }}">{{ $row['medianUsable'] ?? '—' }}</td>
                                <td class="{{ $td }}">{{ $row['medianTerminal'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-signpost class="mt-4" />
        </section>

        {{-- Walled-off, admin-granted interpretation. Built only when the gate allows;
             the directive wording lives in App\Compliance\Interpretation, never here. --}}
        @if ($interpretation)
            @include('livewire.partials.interpretation', ['interpretation' => $interpretation])
        @endif
    @endif
</div>
