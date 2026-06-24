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
                {{ $scenario->household->name }} · base tax year {{ $scenario->base_tax_year }} ·
                primary option: {{ \App\Forecast\ResultPresenter::variantLabel($scenario->variant) }}
            </p>
        </div>
        <a href="{{ route('dashboard') }}" class="shrink-0 text-sm text-blue-700 underline">Back to forecasts</a>
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

    @if (! $presented)
        <div class="{{ $card }} text-sm text-gray-600">
            <p>No completed run yet. Run a preview to see headline figures, then the full forecast for the precise picture.</p>
        </div>
    @else
        @php $variants = $presented['variants']; $primary = $presented['primary']; @endphp

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
                            <div class="flex justify-between"><dt class="text-gray-600">Median wealth left</dt><dd class="font-medium">{{ $v['terminalP50'] }}</dd></div>
                        </dl>
                    </div>
                @endforeach
            </div>
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
                    aria-label="Bar chart of median terminal wealth by housing option. The full figures are in the data table below."></div>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">Success probabilities, chance of running out and median terminal wealth by housing option</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="{{ $th }}">Option</th>
                            <th scope="col" class="{{ $th }}">Essentials met</th>
                            <th scope="col" class="{{ $th }}">Full spend met</th>
                            <th scope="col" class="{{ $th }}">Runs out</th>
                            <th scope="col" class="{{ $th }}">Median wealth left</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($comparison['rows'] as $row)
                            <tr>
                                <th scope="row" class="{{ $td }} font-medium">{{ $row['label'] }}</th>
                                <td class="{{ $td }}">{{ $row['successEssentials'] }}</td>
                                <td class="{{ $td }}">{{ $row['successFullSpend'] }}</td>
                                <td class="{{ $td }}">{{ $row['depletionRate'] }}</td>
                                <td class="{{ $td }}">{{ $row['medianTerminal'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-4 text-xs text-gray-600">
                These figures illustrate the consequences of the numbers you entered under this run's assumptions. Pension and
                housing decisions are significant; free, impartial guidance is available from
                <a class="underline" href="https://www.moneyhelper.org.uk/en/pensions-and-retirement/pension-wise" rel="noopener">Pension Wise</a>
                and <a class="underline" href="https://www.moneyhelper.org.uk/" rel="noopener">MoneyHelper</a>.
            </p>
        </section>
    @endif
</div>
