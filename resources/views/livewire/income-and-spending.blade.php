<div class="mx-auto max-w-4xl">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-3xl font-bold text-gray-900">Your income and spending</h1>
        <div class="flex items-center gap-4 text-sm font-medium text-blue-600">
            <a href="{{ route('scenarios.results', $scenario) }}" class="hover:text-blue-700">Full results</a>
            <a href="{{ route('dashboard') }}" class="hover:text-blue-700">Back to forecasts</a>
        </div>
    </div>
    <p class="mt-1 text-base text-gray-600">{{ $scenario->name }}: {{ $plan }}. Every figure is a month, in today's money, on the expected path.</p>

    @if ($money['stages'] === [])
        <p class="mt-6 text-gray-700">This plan has no years to show yet. Check its inputs.</p>
    @else
        {{-- 1. Where the money comes from. --}}
        <section data-income-sources aria-labelledby="income-heading" class="mt-6 overflow-x-auto rounded-xl border border-gray-200 bg-white p-6">
            <h2 id="income-heading" class="text-xl font-semibold text-gray-900">Where your money comes from</h2>
            <table class="mt-4 w-full text-left text-base">
                <thead>
                    <tr class="border-b border-gray-200 text-sm text-gray-600">
                        <th class="py-2 pr-4 font-medium">A month</th>
                        @foreach ($money['stages'] as $stage)
                            <th class="py-2 pl-4 text-right font-medium">{{ $stage['stage'] }}<br><span class="font-normal">{{ $stage['year'] }}, aged {{ $stage['ages'] }}</span></th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ([['Regular income', $money['regular']], ['Taken from your pensions and savings', $money['drawn']]] as [$group, $rows])
                        @if ($rows !== [])
                            <tr><th colspan="{{ count($money['stages']) + 1 }}" class="pt-3 pb-1 text-sm font-semibold text-gray-700">{{ $group }}</th></tr>
                            @foreach ($rows as $row)
                                <tr class="border-b border-gray-100">
                                    <td class="py-1.5 pr-4">{{ $row['label'] }}</td>
                                    @foreach ($row['monthly'] as $amount)
                                        <td class="py-1.5 pl-4 text-right tabular-nums">{{ $amount->isZero() ? '-' : $amount->format() }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        @endif
                    @endforeach
                    <tr class="border-b border-gray-200">
                        <td class="py-1.5 pr-4">Less tax</td>
                        @foreach ($money['tax'] as $amount)
                            <td class="py-1.5 pl-4 text-right tabular-nums">{{ $amount->isZero() ? '-' : '-'.$amount->format() }}</td>
                        @endforeach
                    </tr>
                    <tr class="font-semibold">
                        <td class="py-2 pr-4">Money to spend</td>
                        @foreach ($money['toSpend'] as $amount)
                            <td class="py-2 pl-4 text-right tabular-nums">{{ $amount->format() }}</td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        </section>

        {{-- 2. What it lets you spend. --}}
        <section data-spending aria-labelledby="spend-heading" class="mt-6 overflow-x-auto rounded-xl border border-gray-200 bg-white p-6">
            <h2 id="spend-heading" class="text-xl font-semibold text-gray-900">What you can spend</h2>
            @if ($safeExtra)
                <p class="mt-2 text-lg text-gray-900">
                    On top of your essentials, you could spend about
                    <strong class="tabular-nums">{{ $safeExtra['monthly']->format() }} a month</strong>{{ $safeExtra['ceilingHit'] ? ' or more' : '' }}
                    on things you choose, every year, and not run short.
                </p>
            @else
                <p class="mt-2 text-lg font-semibold text-red-800">This plan cannot pay for your essentials every year, even with nothing spent on extras.</p>
            @endif
            <table class="mt-4 w-full text-left text-base">
                <thead>
                    <tr class="border-b border-gray-200 text-sm text-gray-600">
                        <th class="py-2 pr-4 font-medium">A month</th>
                        @foreach ($money['stages'] as $stage)
                            <th class="py-2 pl-4 text-right font-medium">{{ $stage['stage'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-b border-gray-100">
                        <td class="py-1.5 pr-4">Money to spend</td>
                        @foreach ($money['toSpend'] as $amount)
                            <td class="py-1.5 pl-4 text-right tabular-nums">{{ $amount->format() }}</td>
                        @endforeach
                    </tr>
                    <tr class="border-b border-gray-100">
                        <td class="py-1.5 pr-4">Your plan spends</td>
                        @foreach ($money['spend'] as $amount)
                            <td class="py-1.5 pl-4 text-right tabular-nums">{{ $amount->format() }}</td>
                        @endforeach
                    </tr>
                    <tr class="border-b border-gray-100 text-gray-600">
                        <td class="py-1.5 pr-4 pl-4">of which essentials (bills, food, home)</td>
                        @foreach ($money['essentials'] as $amount)
                            <td class="py-1.5 pl-4 text-right tabular-nums">{{ $amount->format() }}</td>
                        @endforeach
                    </tr>
                    <tr class="font-semibold">
                        <td class="py-2 pr-4">Left over, saved</td>
                        @foreach ($money['leftOver'] as $amount)
                            <td class="py-2 pl-4 text-right tabular-nums {{ $amount->isNegative() ? 'text-red-800' : '' }}">{{ $amount->isNegative() ? 'Short by '.$amount->abs()->format() : $amount->format() }}</td>
                        @endforeach
                    </tr>
                    @if ($money['couldSpend'])
                        <tr data-could-spend class="border-t border-gray-200 font-semibold text-green-800">
                            <td class="py-2 pr-4">You could spend up to</td>
                            @foreach ($money['couldSpend'] as $amount)
                                <td class="py-2 pl-4 text-right tabular-nums">{{ $amount ? $amount->format().($safeExtra['ceilingHit'] ? '+' : '') : '-' }}</td>
                            @endforeach
                        </tr>
                    @endif
                </tbody>
            </table>
            @if ($money['couldSpend'] && $money['couldSpend'][0] && $money['spend'][0]->greaterThan($money['couldSpend'][0]))
                <p data-overspend class="mt-3 font-semibold text-red-800">Your plan spends {{ $money['spend'][0]->minus($money['couldSpend'][0])->format() }} a month more than it can keep up. Spend less, or the money runs short later.</p>
            @endif
            <p class="mt-3 text-sm text-gray-600">"You could spend up to" is the same plan run with the most it can spend on extras every year and not run short. Read it beside the chance of success on the results page: it is the expected path, not an unlucky one.</p>
        </section>
    @endif
</div>
