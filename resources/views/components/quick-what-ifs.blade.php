@props(['scenario'])

{{-- One-click what-ifs: each posts a preset that creates a delta-child of the base (the
     same shape as a hand-built what-if) and opens its results. Posting via a form (not a
     link) because each one creates a scenario. --}}
<div class="flex flex-wrap items-center gap-2">
    <span class="text-xs font-medium text-gray-500">Quick what-if:</span>
    @foreach (\App\Forecast\QuickWhatIf::PRESETS as $preset => $label)
        <form method="POST" action="{{ route('scenarios.whatif.quick', $scenario) }}">
            @csrf
            <input type="hidden" name="preset" value="{{ $preset }}">
            <button type="submit"
                class="inline-flex items-center rounded-full border border-amber-300 bg-amber-50 px-3 py-1 text-xs font-medium text-amber-800 hover:bg-amber-100">
                {{ $label }}
            </button>
        </form>
    @endforeach
</div>
