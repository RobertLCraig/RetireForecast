<div>
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold text-gray-900">Your forecasts</h1>
        <a href="{{ route('scenarios.create') }}" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">New forecast</a>
    </div>

    @if (session('status'))
        <div role="status" class="mt-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>
    @endif

    @if ($scenarios->isEmpty())
        <div class="mt-8 rounded-lg border border-dashed border-gray-300 bg-white px-6 py-12 text-center">
            <p class="text-gray-700">You have not built a forecast yet.</p>
            <p class="mt-1 text-sm text-gray-500">Start one to compare staying put, buying somewhere cheaper, or selling and renting.</p>
            <a href="{{ route('scenarios.create') }}" class="mt-4 inline-block rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Build your first forecast</a>
        </div>
    @else
        <ul class="mt-6 divide-y divide-gray-200 rounded-lg border border-gray-200 bg-white">
            @foreach ($scenarios as $scenario)
                <li>
                    <a href="{{ route('scenarios.results', $scenario) }}" class="flex items-center justify-between px-4 py-3 hover:bg-gray-50">
                        <div>
                            <p class="font-medium text-gray-900">{{ $scenario->name }}</p>
                            <p class="text-sm text-gray-500">
                                {{ $scenario->household->name }} · base tax year {{ $scenario->base_tax_year }}
                            </p>
                        </div>
                        <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700">
                            {{ ucfirst($scenario->status->value) }}
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
