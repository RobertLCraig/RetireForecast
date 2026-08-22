{{-- The three hero time-series charts (C1 income staircase, C2 wealth composition, C3 costs
     over time) for the selected strategy. Each is a stacked-area picture of figures the
     year-by-year cashflow ladder below also lists, built from the SAME deterministic forecast,
     so a chart can never drift from the table. Every chart ships its <details> table twin (the
     accessible source of truth; the canvas is a progressive enhancement). Figures default to
     today's money, like the ladder and fan; the toggle switches all three to the pounds of each
     year, taken from the engine's own pre-deflation figures. --}}
<section id="sec-money-over-time" {{ $panel('sec-money-over-time') }} aria-labelledby="money-over-time-heading" class="{{ $card }} scroll-mt-6 space-y-8">
    <div>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="money-over-time-heading" class="text-xl font-semibold text-gray-900">
                Money over time
                <span class="ml-2 align-middle rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">{{ $ladderSelectedLabel }}</span>
            </h2>
            @if ($timeSeries['nominalAvailable'])
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" wire:model.live="nominalPounds" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    Show pounds of the day
                </label>
            @endif
        </div>
        <p class="mt-1 text-sm text-gray-600">
            The same year-by-year figures as the cashflow table below, drawn as pictures: where your income comes from, where your wealth sits, and how your spending changes as you age. These three charts are in {{ $timeSeries['basisLabel'] }}, and follow the central best-estimate projection (one illustrative path, not a probability). The dashed verticals mark the life events that drive each step change.
            @if ($timeSeries['nominal'])
                <strong>Later figures look bigger because prices rise, not because you are better off</strong>; the rest of this page stays in today's money, so compare only like with like. Untick the box to put these charts back on that basis.
            @elseif ($timeSeries['nominalAvailable'])
                Tick "pounds of the day" to see the same projection in the cash amounts of each future year instead, which is how a statement in 2045 would read.
            @endif
        </p>
    </div>

    {{-- C1 — income staircase --}}
    <div>
        <h3 class="text-base font-semibold text-gray-900">Where your income comes from</h3>
        <p class="mt-1 text-sm text-gray-600">Each band is one source of income, stacked to the year's total. Watch the handover as earnings stop and pensions, the State Pension and any drawdown take over.</p>
        <div class="mt-4" wire:key="ts-income-{{ $ladderSelected }}-{{ $timeSeries['nominal'] ? 'cash' : 'real' }}">
            <div wire:ignore>
                <div x-data="chart(@js($timeSeries['income']['options']))" role="img"
                    aria-label="Stacked-area chart of income by source over time for {{ $ladderSelectedLabel }}. The full figures are in the data table below."></div>
            </div>
        </div>
        <details class="mt-3">
            <summary class="cursor-pointer text-sm font-medium text-blue-700">Show the numbers behind this chart</summary>
            <div class="mt-2 overflow-x-auto" tabindex="0">
                <table class="w-full text-sm whitespace-nowrap">
                    <caption class="sr-only">Income by source ({{ $timeSeries['basisShort'] }}) by calendar year for {{ $ladderSelectedLabel }}</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="{{ $th }}">Year</th>
                            <th scope="col" class="{{ $th }}">Age(s)</th>
                            @foreach ($timeSeries['income']['sources'] as $source)
                                <th scope="col" class="{{ $th }} text-right">{{ $timeSeries['income']['sourceLabels'][$source] }}</th>
                            @endforeach
                            <th scope="col" class="{{ $th }} text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($timeSeries['income']['rows'] as $row)
                            <tr>
                                <th scope="row" class="{{ $td }} font-medium">{{ $row['year'] }}</th>
                                <td class="{{ $td }}">{{ $row['ages'] }}</td>
                                @foreach ($timeSeries['income']['sources'] as $source)
                                    <td class="{{ $td }} text-right">{{ $row['income'][$source] }}</td>
                                @endforeach
                                <td class="{{ $td }} text-right font-medium">{{ $row['total'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </div>

    {{-- C2 — wealth composition --}}
    <div>
        <h3 class="text-base font-semibold text-gray-900">Where your wealth is</h3>
        <p class="mt-1 text-sm text-gray-600">Your net worth split into its three parts, stacked to the total: pensions, savings &amp; investments, and the equity in your home (net of any mortgage). Shows how the balance shifts as pots are drawn on and the home is kept or sold.</p>
        <div class="mt-4" wire:key="ts-wealth-{{ $ladderSelected }}-{{ $timeSeries['nominal'] ? 'cash' : 'real' }}">
            <div wire:ignore>
                <div x-data="chart(@js($timeSeries['wealth']['options']))" role="img"
                    aria-label="Stacked-area chart of wealth by type (pensions, savings and investments, home equity) over time for {{ $ladderSelectedLabel }}. The full figures are in the data table below."></div>
            </div>
        </div>
        <details class="mt-3">
            <summary class="cursor-pointer text-sm font-medium text-blue-700">Show the numbers behind this chart</summary>
            <div class="mt-2 overflow-x-auto" tabindex="0">
                <table class="w-full text-sm whitespace-nowrap">
                    <caption class="sr-only">Wealth by type ({{ $timeSeries['basisShort'] }}) by calendar year for {{ $ladderSelectedLabel }}</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="{{ $th }}">Year</th>
                            <th scope="col" class="{{ $th }}">Age(s)</th>
                            <th scope="col" class="{{ $th }} text-right">Pensions</th>
                            <th scope="col" class="{{ $th }} text-right">Savings &amp; investments</th>
                            <th scope="col" class="{{ $th }} text-right">Home equity</th>
                            <th scope="col" class="{{ $th }} text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($timeSeries['wealth']['rows'] as $row)
                            <tr>
                                <th scope="row" class="{{ $td }} font-medium">{{ $row['year'] }}</th>
                                <td class="{{ $td }}">{{ $row['ages'] }}</td>
                                <td class="{{ $td }} text-right">{{ $row['pension'] }}</td>
                                <td class="{{ $td }} text-right">{{ $row['liquid'] }}</td>
                                <td class="{{ $td }} text-right">{{ $row['homeEquity'] }}</td>
                                <td class="{{ $td }} text-right font-medium">{{ $row['total'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </div>

    {{-- C3 — costs over time --}}
    <div>
        <h3 class="text-base font-semibold text-gray-900">What you spend, and how it changes</h3>
        <p class="mt-1 text-sm text-gray-600">Your target spending each year, split into the essential floor and the discretionary extra on top. The shape reflects the age-varying spending "smile" (more active early, easing in the middle years).</p>
        <div class="mt-4" wire:key="ts-costs-{{ $ladderSelected }}-{{ $timeSeries['nominal'] ? 'cash' : 'real' }}">
            <div wire:ignore>
                <div x-data="chart(@js($timeSeries['costs']['options']))" role="img"
                    aria-label="Stacked-area chart of essential and discretionary spending over time for {{ $ladderSelectedLabel }}. The full figures are in the data table below."></div>
            </div>
        </div>
        <details class="mt-3">
            <summary class="cursor-pointer text-sm font-medium text-blue-700">Show the numbers behind this chart</summary>
            <div class="mt-2 overflow-x-auto" tabindex="0">
                <table class="w-full text-sm whitespace-nowrap">
                    <caption class="sr-only">Spending split into essential and discretionary ({{ $timeSeries['basisShort'] }}) by calendar year for {{ $ladderSelectedLabel }}</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="{{ $th }}">Year</th>
                            <th scope="col" class="{{ $th }}">Age(s)</th>
                            <th scope="col" class="{{ $th }} text-right">Essential</th>
                            <th scope="col" class="{{ $th }} text-right">Discretionary</th>
                            <th scope="col" class="{{ $th }} text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($timeSeries['costs']['rows'] as $row)
                            <tr>
                                <th scope="row" class="{{ $td }} font-medium">{{ $row['year'] }}</th>
                                <td class="{{ $td }}">{{ $row['ages'] }}</td>
                                <td class="{{ $td }} text-right">{{ $row['essential'] }}</td>
                                <td class="{{ $td }} text-right">{{ $row['discretionary'] }}</td>
                                <td class="{{ $td }} text-right font-medium">{{ $row['total'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </div>
</section>
