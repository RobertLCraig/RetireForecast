<div class="mx-auto max-w-3xl">
    {{-- Header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-3xl font-bold text-gray-900">What you can afford</h1>
        <div class="flex items-center gap-4 text-sm font-medium text-blue-600">
            <a href="{{ route('scenarios.compare', $base) }}" class="hover:text-blue-700">See the full comparison</a>
            <a href="{{ route('dashboard') }}" class="hover:text-blue-700">Back to forecasts</a>
        </div>
    </div>
    <p class="mt-1 text-base text-gray-600">Every plan you’ve entered, answered as a plain yes or no. The plans that work come first.</p>

    {{-- The bottom line, up top where an impatient reader will actually see it. --}}
    @php($best = $bottomLine['best'])
    <div class="mt-6 rounded-xl border-2 {{ $best ? 'border-green-300 bg-green-50' : 'border-red-300 bg-red-50' }} p-5">
        <p class="text-sm font-semibold uppercase tracking-wide {{ $best ? 'text-green-800' : 'text-red-800' }}">The bottom line</p>
        <p class="mt-2 text-xl leading-relaxed text-gray-900">{{ $bottomLine['headline'] }}</p>
        @if ($best)
            <p class="mt-2 text-base text-gray-700">
                {{ $bottomLine['workCount'] }} of your {{ $bottomLine['total'] }} plans keep the essentials paid for life.
            </p>
            {{-- The verdict above is the expected, care-free path; this qualifies it with the care risk (A2). --}}
            <p class="mt-2 text-base font-medium text-gray-700">🏥 {{ $bottomLine['careCaveat'] }}</p>
        @endif

        @if ($anyUnchecked)
            {{-- One click runs the full 10,000-future check on every plan (in the background), so the
                 "how sure" figures fill in. Hands off to Compare, which shows the live progress. --}}
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <button type="button" wire:click="checkHowSure" wire:loading.attr="disabled"
                    class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700 disabled:opacity-60">
                    <span wire:loading.remove wire:target="checkHowSure">Check how sure — run the full future test</span>
                    <span wire:loading wire:target="checkHowSure">Starting…</span>
                </button>
                <span class="text-sm text-gray-600">Some plans haven’t been through the full test yet. It runs in the background (a minute or two).</span>
            </div>
        @endif

        @if ($canInterpret && $best)
            {{-- Directive guidance: only reachable behind the walled-off `interpret` ability. --}}
            <p class="mt-3 rounded-lg bg-white/70 px-4 py-3 text-base text-gray-800">
                <span class="font-semibold">If keeping a roof over you and the bills paid is the priority,</span>
                the plan to lean towards is <span class="font-semibold">{{ $best['title'] }}</span>{{ $best['monthlyRentLabel'] ? ' at '.$best['monthlyRentLabel'].' a month' : '' }} —
                it is the safest of your plans that still lasts for life.
            </p>
        @endif
    </div>

    {{-- The plans that work --}}
    <h2 class="mt-10 text-2xl font-bold text-gray-900">✅ Plans that work</h2>
    <p class="mt-1 text-base text-gray-600">These keep your essential bills paid for the rest of your life.</p>

    @if (empty($working))
        <div class="mt-4 rounded-lg border border-dashed border-gray-300 bg-white px-5 py-8 text-center text-gray-700">
            On the figures entered, none of these plans keep the essentials paid all the way through.
            Look at the options below — the year each one runs short is shown — or try a what-if with more income
            (working a little longer, help from family, or a smaller home).
        </div>
    @else
        <ul class="mt-4 space-y-4">
            @foreach ($working as $card)
                <li class="rounded-xl border-2 {{ $card['tier'] === 'comfortable' ? 'border-green-300' : 'border-amber-300' }} bg-white p-5">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p class="text-xl font-bold text-gray-900">{{ $card['title'] }}</p>
                            <p class="text-sm font-medium text-gray-500">
                                {{ $card['variantLabel'].($card['monthlyRentLabel'] ? ' · '.$card['monthlyRentLabel'].' a month rent' : '') }}
                            </p>
                        </div>
                        <span class="shrink-0 rounded-full px-3 py-1 text-sm font-semibold {{ $card['tier'] === 'comfortable' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">
                            {{ $card['tier'] === 'comfortable' ? 'Affordable' : 'Essentials covered' }}
                        </span>
                    </div>

                    <p class="mt-3 text-lg leading-relaxed text-gray-900">{{ $card['verdict'] }}</p>

                    {{-- The care-stress companion (A2): the SAME expected path with an adverse ~4-year
                         nursing spell, so "works for life" is never shown against a care-free path. --}}
                    <p class="mt-3 rounded-lg px-4 py-3 text-base leading-relaxed {{ $card['careStress']['holds'] ? 'bg-green-50 text-green-900' : 'bg-amber-50 text-amber-900' }}">
                        🏥 {{ $card['careStress']['verdict'] }}
                    </p>

                    <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        {{-- The question the reader actually arrived with. Leads the grid because
                             "how much can we spend?" beats "how much is left when we're dead". --}}
                        <div class="rounded-lg bg-blue-50 px-4 py-3 sm:col-span-2">
                            <dt class="text-sm text-gray-600">Money to live on, each month</dt>
                            <dd class="text-lg font-semibold text-gray-900">
                                {{ $card['spendable']['now']['monthlyAllowance'] }}
                                <span class="text-base font-normal text-gray-700">
                                    — of which {{ $card['spendable']['now']['monthlyFree'] }} is yours to choose
                                    (holidays, treats, anything you like)
                                </span>
                            </dd>
                            @if ($card['spendable']['survivor'])
                                <dd class="mt-1 text-sm text-gray-700">
                                    If one of you is on your own, from about {{ $card['spendable']['survivorFromYear'] }}:
                                    <strong>{{ $card['spendable']['survivor']['monthlyAllowance'] }} a month</strong>,
                                    of which {{ $card['spendable']['survivor']['monthlyFree'] }} is free to choose.
                                </dd>
                            @endif
                            <dd class="mt-1 text-xs text-gray-500">
                                In today's money, and only what this plan can actually pay for.
                                You'd also have {{ $card['spendable']['now']['availableCapital'] }} in cash and savings to hand.
                            </dd>
                            {{-- The SOLVED figure: not what this plan budgets, but the most it could
                                 fund every year without ever falling short. The holiday budget. --}}
                            @if ($card['affordableFreeSpendMonthly'])
                                <dd class="mt-2 border-t border-blue-200 pt-2 text-sm text-gray-800">
                                    <strong>Most you could spend on treats and holidays:</strong>
                                    about <strong>{{ $card['affordableFreeSpendMonthly'] }} a month</strong>
                                    ({{ $card['affordableFreeSpendAnnual'] }} a year){{ $card['affordableFreeSpendUncapped'] ? ' or more' : '' }},
                                    on top of your essentials — the most this plan could pay for
                                    <em>every</em> year without ever running short.
                                </dd>
                            @endif
                        </div>
                        <div class="rounded-lg bg-gray-50 px-4 py-3">
                            <dt class="text-sm text-gray-500">Money left at the end</dt>
                            <dd class="text-lg font-semibold text-gray-900">about {{ $card['moneyLeftRough'] }}</dd>
                        </div>
                        <div class="rounded-lg bg-gray-50 px-4 py-3">
                            <dt class="text-sm text-gray-500">How sure is this?</dt>
                            <dd class="text-lg font-semibold text-gray-900">
                                @if ($card['mcEssentials'] !== null)
                                    essentials last in {{ $card['mcEssentials'] }} of futures
                                @else
                                    <span class="text-base font-normal text-gray-500">not yet checked — <a href="{{ route('scenarios.compare', $base) }}" class="text-blue-600 underline hover:no-underline">run the full check</a></span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </li>
            @endforeach
        </ul>
    @endif

    {{-- The plans that don't work — kept, not hidden, so the reader can see WHY each is ruled out. --}}
    @if (! empty($failing))
        <details class="mt-10 group">
            <summary class="cursor-pointer list-none">
                <h2 class="inline text-2xl font-bold text-gray-900">❌ Plans that don’t add up ({{ count($failing) }})</h2>
                <span class="ml-2 text-sm font-medium text-blue-600 group-open:hidden">show</span>
                <span class="ml-2 hidden text-sm font-medium text-blue-600 group-open:inline">hide</span>
                <p class="mt-1 text-base text-gray-600">On these, the money runs short before the end. Each shows the year it happens.</p>
            </summary>
            <ul class="mt-4 space-y-3">
                @foreach ($failing as $card)
                    <li class="rounded-xl border border-red-200 bg-white p-5">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="text-lg font-bold text-gray-900">{{ $card['title'] }}</p>
                                <p class="text-sm font-medium text-gray-500">
                                    {{ $card['variantLabel'].($card['monthlyRentLabel'] ? ' · '.$card['monthlyRentLabel'].' a month rent' : '') }}
                                </p>
                            </div>
                            @if ($card['runsOutYear'])
                                <span class="shrink-0 rounded-full bg-red-100 px-3 py-1 text-sm font-semibold text-red-800">
                                    runs out {{ $card['runsOutYear'] }}
                                </span>
                            @endif
                        </div>
                        <p class="mt-3 text-base leading-relaxed text-gray-800">{{ $card['verdict'] }}</p>
                        <p class="mt-2 text-sm leading-relaxed text-gray-600">🏥 {{ $card['careStress']['verdict'] }}</p>
                        @if ($card['mcEssentials'] !== null)
                            <p class="mt-2 text-sm text-gray-500">Full check: essentials last in only {{ $card['mcEssentials'] }} of possible futures.</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    {{-- Honesty note: what "works" here means, and its limits. --}}
    <div class="mt-10 rounded-lg border border-gray-200 bg-white px-5 py-4 text-sm leading-relaxed text-gray-600">
        <p class="font-semibold text-gray-700">How to read this</p>
        <p class="mt-1">
            “Works” means the <span class="font-medium">essential</span> bills — housing, food, heating, the must-pays —
            stay covered every year to the end, on the <span class="font-medium">expected path</span> (average investment returns
            and typical lifespans). Where a plan has been through the full check, the “how sure” figure shows how often it lasts
            once we allow for bad luck with returns and living longer — always look at that too before deciding.
            The <span class="font-medium">🏥 care line</span> on each plan is a separate stress: it shows what would happen if one of you
            needed about four years of nursing care (~£1,800 a week) later in life. The main verdict assumes no such care,
            so read the two together — care is the single biggest risk to most plans.
            These are illustrations of the figures you entered, not financial advice.
            For free, impartial help see <a class="underline" href="https://www.moneyhelper.org.uk/" rel="noopener">MoneyHelper</a>
            and <a class="underline" href="https://www.moneyhelper.org.uk/en/pensions-and-retirement/pension-wise" rel="noopener">Pension Wise</a>.
        </p>
    </div>
</div>
