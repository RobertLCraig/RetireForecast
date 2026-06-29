<div>
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Compare what-ifs</h1>
            <p class="mt-1 text-sm text-gray-500">Base plan: {{ $base->name }}</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('scenarios.child', $base) }}" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Create a what-if</a>
            <a href="{{ route('dashboard') }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">Back to forecasts</a>
        </div>
    </div>

    <p class="mt-4 max-w-3xl text-sm text-gray-600">
        Each plan below is shown using its central (best-estimate) projection, so the figures appear without
        running a full simulation. A what-if changes one or more values on the base plan; everything it does not
        change tracks the base. The figures are shown side by side for you to read, not ranked.
    </p>

    <div class="mt-6 overflow-x-auto rounded-lg border border-gray-200 bg-white" tabindex="0">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <caption class="sr-only">Your base plan and its what-ifs compared on their central projection.</caption>
            <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                <tr>
                    <th scope="col" class="px-4 py-3">Plan</th>
                    <th scope="col" class="px-4 py-3">Housing choice</th>
                    <th scope="col" class="px-4 py-3">Essentials covered every year</th>
                    <th scope="col" class="px-4 py-3">Money lasts</th>
                    <th scope="col" class="px-4 py-3">Usable wealth left (excl. home)</th>
                    <th scope="col" class="px-4 py-3">Total wealth left (incl. home)</th>
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
                            @if ($plan['orphans'] !== [])
                                <span class="mt-1 block text-xs font-normal text-amber-700">Some of this what-if's changes no longer apply because the base plan changed. Re-open it to review.</span>
                            @endif
                        </th>
                        <td class="px-4 py-3 text-gray-700">{{ $plan['variant'] }}</td>
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
            read the trajectories against each other. A line burning down to zero is money running out. Figures are
            in today's money. These are consequences, not a recommendation.
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

    <div class="mt-6">
        <x-disclaimer.result />
    </div>
</div>
