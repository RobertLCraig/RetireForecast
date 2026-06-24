<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Every statutory figure the engine uses, grouped by domain, with its source and the date it was verified.
        These live in code as the single source of truth and are not editable here.
    </p>

    <div class="space-y-8">
        @foreach ($this->years() as $year)
            <section>
                <div class="flex items-baseline justify-between">
                    <h2 class="text-xl font-bold">Tax year {{ $year['taxYear'] }}</h2>
                    <span class="text-sm text-gray-500">Verified {{ $year['verifiedOn'] }}</span>
                </div>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    @foreach ($year['groups'] as $group)
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                            <div class="flex items-center justify-between">
                                <h3 class="font-semibold">{{ $group['name'] }}</h3>
                                @if ($group['source'])
                                    <a href="{{ $group['source'] }}" target="_blank" rel="noopener"
                                       class="text-xs text-primary-600 underline">source</a>
                                @endif
                            </div>
                            <dl class="mt-2 space-y-1 text-sm">
                                @foreach ($group['figures'] as $label => $value)
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-gray-500">{{ $label }}</dt>
                                        <dd class="font-mono">{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
