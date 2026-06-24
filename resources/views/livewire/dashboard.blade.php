<div>
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold text-gray-900">Your forecasts</h1>
    </div>

    @if ($scenarios->isEmpty())
        <div class="mt-8 rounded-lg border border-dashed border-gray-300 bg-white px-6 py-12 text-center">
            <p class="text-gray-700">You have not built a forecast yet.</p>
            <p class="mt-1 text-sm text-gray-500">Start one to compare staying put, buying somewhere cheaper, or selling and renting.</p>
        </div>
    @else
        <ul class="mt-6 divide-y divide-gray-200 rounded-lg border border-gray-200 bg-white">
            @foreach ($scenarios as $scenario)
                <li class="flex items-center justify-between px-4 py-3">
                    <div>
                        <p class="font-medium text-gray-900">{{ $scenario->name }}</p>
                        <p class="text-sm text-gray-500">
                            {{ $scenario->household->name }} · base tax year {{ $scenario->base_tax_year }}
                        </p>
                    </div>
                    <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700">
                        {{ ucfirst($scenario->status->value) }}
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
