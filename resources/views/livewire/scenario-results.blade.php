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
        <section aria-labelledby="sensitivity-heading" class="{{ $card }}">
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
            <x-signpost class="mt-4" />
        </section>
    @endif

    {{-- Show-your-working: the assumptions every figure on this page rests on, surfaced so a
         headline figure can be traced to its basis. Factual, never a recommendation. --}}
    <section aria-labelledby="assumptions-heading" class="{{ $card }}">
        <h2 id="assumptions-heading" class="text-xl font-semibold text-gray-900">The assumptions behind these figures</h2>
        <p class="mt-1 text-sm text-gray-600">
            Every figure on this page rests on these assumptions. Returns and growth are <strong>real</strong> — they are above inflation, so amounts stay in today's money. The "How sensitive is this?" section above shows how much the answer changes under different sets.
        </p>
        <dl class="mt-4 grid gap-3 sm:grid-cols-2">
            @foreach ($assumptions['economic'] as $row)
                <div class="rounded-md border border-gray-200 p-3">
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-sm text-gray-700">{{ $row['label'] }}</dt>
                        <dd class="text-sm font-semibold text-gray-900 tabular-nums">{{ $row['value'] }}</dd>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">{{ $row['note'] }}</p>
                </div>
            @endforeach
        </dl>
        <p class="mt-3 text-xs text-gray-500">
            Investment growth blends {{ $assumptions['mix'] }}. Assumption set: <strong>{{ $assumptions['setName'] }}</strong>. {{ $assumptions['sourceNote'] }}
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
        <section aria-labelledby="sale-heading" class="{{ $card }}">
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
                        <tr><th scope="row" class="{{ $td }} text-left">less selling costs ({{ $se['sellingRatePct'] }} of the sale price@if ($se['sellingRateIsDefault']), assumed@endif)</th><td class="{{ $td }} text-right tabular-nums">−{{ $se['proceeds']['sellingCosts'] }}</td></tr>
                        <tr><th scope="row" class="{{ $td }} text-left">less capital gains tax (main home, fully relieved)</th><td class="{{ $td }} text-right tabular-nums">−{{ $se['proceeds']['cgt'] }}</td></tr>
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
                    <p class="mt-1 text-sm text-gray-700">All {{ $se['rent']['invested'] }} of the net proceeds is invested.@if ($se['rent']['annualRent']) Rent of {{ $se['rent']['annualRent'] }} a year is then paid from income.@endif</p>
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
                'retirement' => 'bg-amber-400',
                'pension_access' => 'bg-blue-400',
                'state_pension' => 'bg-green-400',
                'death' => 'bg-gray-500',
            ];
        @endphp
        <section aria-labelledby="milestones-heading" class="{{ $card }}">
            <h2 id="milestones-heading" class="text-xl font-semibold text-gray-900">When the big events happen</h2>
            <p class="mt-1 text-sm text-gray-600">
                The major life events in this forecast, in order. These drive the step changes in the year-by-year cashflow below — when earnings stop, a pension starts, or the household changes size. Ages are each person's age in that year.
            </p>
            <ul class="mt-4 space-y-2">
                @foreach ($milestones as $m)
                    <li class="flex items-baseline gap-3 text-sm">
                        <span class="w-12 shrink-0 font-semibold tabular-nums text-gray-900">{{ $m['year'] }}</span>
                        <span class="h-2 w-2 shrink-0 self-center rounded-full {{ $milestoneDot[$m['kind']] ?? 'bg-gray-300' }}" aria-hidden="true"></span>
                        <span class="text-gray-700">{{ $m['label'] }} <span class="text-gray-500">(age {{ $m['age'] }})</span></span>
                    </li>
                @endforeach
            </ul>
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
                The central best-estimate projection, year by year: where income comes from, the tax on it, the spend it has to meet (split into its essential floor and discretionary remainder), and the usable (excl. home) and total (incl. home) wealth carried forward. Figures are in today's money. This is one illustrative path, not a probability.
            </p>
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
                                    <span class="block text-xs text-gray-500">ess {{ $row['essentialSpend'] }} · disc {{ $row['discretionarySpend'] }}</span>
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
                Under this run's assumptions, across {{ $resultsRun->n_paths }} simulated futures
                ({{ $resultsRun->mode->value }} run, seed {{ $resultsRun->seed }}).
            </p>
            <div class="grid gap-4 md:grid-cols-3">
                @foreach ($variants as $key => $v)
                    @php
                        $verdictStyle = [
                            'none' => 'bg-green-50 text-green-800',
                            'low' => 'bg-green-50 text-green-800',
                            'medium' => 'bg-amber-50 text-amber-800',
                            'high' => 'bg-red-50 text-red-800',
                        ][$v['verdict']['level']];
                    @endphp
                    <div class="{{ $card }} {{ $key === $primary ? 'ring-2 ring-blue-500' : '' }}">
                        <h3 class="font-semibold text-gray-900">{{ $v['label'] }}@if ($key === $primary)<span class="ml-2 rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-800">primary</span>@endif</h3>
                        <p class="mt-2 rounded-md px-3 py-2 text-sm font-medium {{ $verdictStyle }}" @if ($v['verdict']['level'] === 'high') role="alert" @endif>{{ $v['verdict']['text'] }}</p>
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
            <p class="mt-3 text-xs text-gray-500">"Chance of running out" counts the simulated futures with at least one year your essential spending isn't fully covered by income and savings — a shortfall a future may later recover from as guaranteed income catches up. "Wealth left" is the median amount at the very end. So an option can leave money at the end yet still have run short along the way — and "total wealth left" includes any home you would still own, which stays high even when the usable cash for day-to-day spending has run out.</p>
        </section>

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
        <section aria-labelledby="fan-heading" class="{{ $card }}">
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

        {{-- Strategy comparison OVER TIME: which keeps the most spendable money for longest --}}
        @php $comparison = $presented['comparison']; @endphp
        <section aria-labelledby="compare-heading" class="{{ $card }}">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="compare-heading" class="text-xl font-semibold text-gray-900">{{ $comparison['usableBasis'] ? 'Spendable money' : 'Total wealth' }} over time, by housing strategy</h2>
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" wire:model.live="includeHome" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    Include home value
                </label>
            </div>
            <p class="mt-1 text-sm text-gray-600">
                Each line is one housing strategy's median {{ $comparison['usableBasis'] ? 'spendable money (excl. home)' : 'total wealth (incl. home)' }}, year by year — all run on identical simulated futures, so the difference is the housing choice alone. A line that stays higher keeps more usable money available as you age; a line reaching £0 is where the typical future runs dry. A strategy can sit higher here yet still carry a greater chance of a shortfall year, so read it alongside the table below. These are consequences, not a recommendation.
            </p>

            <div class="mt-4" wire:key="compare-chart-{{ $includeHome ? 'incl' : 'excl' }}">
                <div wire:ignore>
                    <div x-data="chart(@js($comparison['options']))" role="img"
                        aria-label="Line chart of median {{ $comparison['usableBasis'] ? 'spendable money excluding the home' : 'total wealth including the home' }} over time for each housing strategy. The full figures are in the data tables below."></div>
                </div>
            </div>

            @include('livewire.partials.tail-note')

            <details class="mt-4">
                <summary class="cursor-pointer text-sm font-medium text-blue-700">Show the year-by-year figures behind this chart</summary>
                <div class="mt-2 overflow-x-auto" tabindex="0">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Median {{ $comparison['usableBasis'] ? 'spendable money (excl. home)' : 'total wealth (incl. home)' }} by calendar year and housing strategy</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="{{ $th }}">Year</th>
                                <th scope="col" class="{{ $th }}">Age(s)</th>
                                @foreach ($comparison['strategies'] as $strategy)
                                    <th scope="col" class="{{ $th }}">{{ $strategy['label'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($comparison['lineRows'] as $row)
                                <tr>
                                    <th scope="row" class="{{ $td }} font-medium">{{ $row['year'] }}</th>
                                    <td class="{{ $td }}">{{ $row['ages'] ?? '—' }}</td>
                                    @foreach ($comparison['strategies'] as $strategy)
                                        <td class="{{ $td }}">{{ $row['cells'][$strategy['key']] ?? '—' }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>

            {{-- Per-strategy summary: the run-out risk and end figures the lines don't show,
                 so a high line never hides a high risk. --}}
            <div class="mt-6">
                <h3 class="text-sm font-semibold text-gray-900">How each strategy ends up</h3>
                <p class="mt-1 text-xs text-gray-500">"Chance of running short" is the share of futures with at least one year your essential spending wasn't fully covered (a future may recover later). "Median … left" is the typical amount at the very end.</p>
                <div class="mt-2 overflow-x-auto" tabindex="0">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Run-out risk and median wealth left by housing strategy</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="{{ $th }}">Strategy</th>
                                <th scope="col" class="{{ $th }}">Essentials always met</th>
                                <th scope="col" class="{{ $th }}">Full lifestyle met</th>
                                <th scope="col" class="{{ $th }}">Chance of running short</th>
                                <th scope="col" class="{{ $th }}">Typically short by</th>
                                <th scope="col" class="{{ $th }}">Median spendable left (excl. home)</th>
                                <th scope="col" class="{{ $th }}">Median total left (incl. home)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($comparison['rows'] as $row)
                                <tr>
                                    <th scope="row" class="{{ $td }} font-medium">{{ $row['label'] }}</th>
                                    <td class="{{ $td }}">{{ $row['successEssentials'] }}</td>
                                    <td class="{{ $td }}">{{ $row['successFullSpend'] }}</td>
                                    <td class="{{ $td }}">{{ $row['depletionRate'] }}</td>
                                    <td class="{{ $td }}">{{ $row['medianDepletionYear'] ?? '—' }}</td>
                                    <td class="{{ $td }}">{{ $row['medianUsable'] ?? '—' }}</td>
                                    <td class="{{ $td }}">{{ $row['medianTerminal'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
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
