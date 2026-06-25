{{-- Per-result guidance-only disclaimer. The plan requires a disclaimer block on every
     Result render (and every export), not just the global footer, so it travels with the
     figures and carries the signpost. --}}
<div {{ $attributes->merge(['class' => 'rounded-lg border border-amber-200 bg-amber-50 p-4']) }}
    role="note" aria-label="Important: guidance only, not financial advice">
    <p class="text-sm font-medium text-amber-900">Guidance only, not financial advice.</p>
    <p class="mt-1 text-xs text-amber-900">
        These figures illustrate the consequences of the numbers and assumptions you entered. They are
        not a personal recommendation and do not tell you what to do. Outcomes depend on assumptions that
        may not hold.
    </p>
    <x-signpost class="mt-2 text-xs text-amber-900" />
</div>
