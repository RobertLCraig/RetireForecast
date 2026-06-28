<div>
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold text-gray-900">Your forecasts</h1>
        <a href="{{ route('scenarios.create') }}" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">New forecast</a>
    </div>

    @if (session('status'))
        <div role="status" class="mt-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>
    @endif

    @if ($draft)
        <div class="mt-4 flex items-center justify-between rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <span>You have a forecast in progress.</span>
            <a href="{{ route('scenarios.create') }}" class="font-medium underline hover:no-underline">Continue your draft</a>
        </div>
    @endif

    @if ($scenarios->isEmpty())
        <div class="mt-8 rounded-lg border border-dashed border-gray-300 bg-white px-6 py-12 text-center">
            <p class="text-gray-700">You have not built a forecast yet.</p>
            <p class="mt-1 text-sm text-gray-500">Start one to compare staying put, buying somewhere cheaper, or selling and renting.</p>
            <a href="{{ route('scenarios.create') }}" class="mt-4 inline-block rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Build your first forecast</a>
        </div>
    @else
        <ul class="mt-6 space-y-4">
            @foreach ($scenarios as $scenario)
                <li class="rounded-lg border border-gray-200 bg-white">
                    <div class="flex items-center justify-between px-4 py-3 hover:bg-gray-50">
                        <a href="{{ route('scenarios.results', $scenario) }}" class="min-w-0 flex-1">
                            <p class="font-medium text-gray-900">{{ $scenario->name }}</p>
                            <p class="text-sm text-gray-500">
                                {{ $scenario->householdName() }} · base tax year {{ $scenario->base_tax_year }}
                            </p>
                        </a>
                        <div class="ml-4 flex items-center gap-3 text-sm font-medium text-blue-600">
                            <a href="{{ route('scenarios.child', $scenario) }}" class="hover:text-blue-700">Create what-if</a>
                            @if ($scenario->children->isNotEmpty())
                                <a href="{{ route('scenarios.compare', $scenario) }}" class="hover:text-blue-700">Compare</a>
                            @endif
                            <a href="{{ route('scenarios.edit', $scenario) }}" class="hover:text-blue-700">Edit</a>
                        </div>
                    </div>

                    @if ($scenario->children->isNotEmpty())
                        <ul class="border-t border-gray-100 bg-gray-50/60">
                            @foreach ($scenario->children as $child)
                                <li class="flex items-center justify-between px-4 py-2 pl-8">
                                    <a href="{{ route('scenarios.results', $child) }}" class="min-w-0 flex-1">
                                        <p class="text-sm text-gray-700">
                                            <span class="text-gray-600">↳ what-if:</span> {{ $child->name }}
                                        </p>
                                    </a>
                                    <a href="{{ route('scenarios.edit', $child) }}" class="ml-4 text-sm font-medium text-blue-600 hover:text-blue-700">Edit</a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
