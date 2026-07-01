{{-- Neutral "how you draw your money" panel: the lifetime tax under the current draw order
     vs filling the tax-free bands first (figures from App\Forecast\WithdrawalStrategyComparison).
     Figures only; any directive steer is the walled-off interpretation partial, included below
     only when the interpret gate allows. --}}
<section id="sec-withdrawal-sequencing" aria-labelledby="withdrawal-heading" class="{{ $card }} scroll-mt-6">
    <h2 id="withdrawal-heading" class="text-xl font-semibold text-gray-900">How you draw your money down</h2>
    <p class="mt-1 text-sm text-gray-600">
        The order you take money from your pension, ISA and other savings changes the tax you pay over your whole plan.
    </p>

    <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <div class="rounded-lg border border-gray-200 p-4">
            <div class="text-xs tracking-wide text-gray-500 uppercase">Spending your savings first (your current order)</div>
            <div class="mt-1 text-2xl font-semibold text-gray-900">{{ $withdrawal['baseline'] }}</div>
            <div class="text-xs text-gray-500">tax paid across the plan</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-4">
            <div class="text-xs tracking-wide text-gray-500 uppercase">Filling your tax-free allowances first</div>
            <div class="mt-1 text-2xl font-semibold text-gray-900">{{ $withdrawal['fillBands'] }}</div>
            <div class="text-xs text-gray-500">tax paid across the plan</div>
        </div>
    </div>

    <p class="mt-3 text-sm text-gray-700">
        @if ($withdrawal['differs'])
            That is <span class="font-semibold">{{ $withdrawal['difference'] }}</span>
            {{ $withdrawal['fillBandsSaves'] ? 'less tax over the plan by filling your tax-free allowances first' : 'less tax over the plan by keeping your current order' }}.
        @else
            On these figures the two orders pay the same tax over the plan.
        @endif
    </p>

    <p class="mt-2 text-xs text-gray-500">
        A central projection on your current assumptions. "Filling your tax-free allowances" draws pension within your
        personal allowance and realises gains within your capital-gains allowance before taxed income; if you receive
        Pension Credit it draws your savings first, so pension income does not reduce the credit.
    </p>

    @if (! empty($withdrawal['steer']))
        <div class="mt-4">
            @include('livewire.partials.interpretation', ['interpretation' => $withdrawal['steer']])
        </div>
    @endif
</section>
