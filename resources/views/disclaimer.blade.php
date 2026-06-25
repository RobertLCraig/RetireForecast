<x-layouts.app title="Before you start">
    <div class="mx-auto max-w-2xl space-y-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Before you start</h1>
            <p class="mt-2 text-gray-700">Please read and accept this once. It explains what RetireForecast is, and what it is not.</p>
        </div>

        <div class="space-y-3 rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-700">
            <p class="font-medium text-gray-900">Guidance only, not financial advice.</p>
            <p>
                RetireForecast illustrates the consequences of the figures and assumptions you enter: how pension
                lump-sum withdrawals are taxed, and whether your money is likely to last across thousands of simulated
                futures. It shows what happens under those assumptions.
            </p>
            <p>
                It does not give a personal recommendation, does not tell you what to do, and is not a substitute for
                regulated financial advice. Every result depends on assumptions that may not hold, and your own
                circumstances may differ.
            </p>
            <p>
                For free, impartial guidance, see
                <a class="underline" href="https://www.moneyhelper.org.uk/en/pensions-and-retirement/pension-wise" rel="noopener">Pension Wise</a>
                and <a class="underline" href="https://www.moneyhelper.org.uk/" rel="noopener">MoneyHelper</a>,
                or speak to an
                <a class="underline" href="https://www.fca.org.uk/consumers/finding-adviser" rel="noopener">FCA-regulated adviser</a>.
            </p>
        </div>

        <form method="POST" action="{{ route('disclaimer.acknowledge') }}">
            @csrf
            <button type="submit"
                class="rounded-md bg-blue-600 px-5 py-2.5 font-medium text-white hover:bg-blue-700">
                I understand — this is guidance, not advice
            </button>
        </form>
    </div>
</x-layouts.app>
