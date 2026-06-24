<x-layouts.app>
    <div class="mx-auto max-w-3xl text-center">
        <h1 class="text-3xl font-semibold text-gray-900">Model your retirement, with the tax shocks shown plainly</h1>
        <p class="mt-4 text-lg text-gray-700">
            RetireForecast illustrates what happens if you sell your home and buy somewhere cheaper, or sell and rent,
            and how pension lump-sum withdrawals are taxed. It runs thousands of futures to show whether the money is
            likely to last.
        </p>
        <p class="mt-2 text-sm text-gray-600">
            This is an education and guidance tool. It shows consequences of the figures you enter. It does not tell
            you what to do.
        </p>

        <div class="mt-8 flex items-center justify-center gap-3">
            <a href="{{ route('register') }}" class="rounded-md bg-blue-600 px-5 py-2.5 font-medium text-white hover:bg-blue-700">
                Get started
            </a>
            <a href="{{ route('login') }}" class="rounded-md border border-gray-300 px-5 py-2.5 font-medium text-gray-800 hover:bg-gray-100">
                Log in
            </a>
        </div>
    </div>
</x-layouts.app>
