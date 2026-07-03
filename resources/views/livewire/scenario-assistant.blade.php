{{-- Local-model scenario assistant. Explains THIS forecast in plain English; it cannot change
     the plan, run anything, or build anything. Runs on a local model only (the household's data
     never leaves the machine). Every figure it states is engine-derived and re-verified at
     runtime (App\Assistant\FigureGrounding); a held-back or unreachable reply is shown as such,
     never a silent blank. Inert unless config('assistant.enabled'). --}}
<section aria-labelledby="assistant-heading" class="rounded-lg border border-gray-200 bg-white p-5">
    <h2 id="assistant-heading" class="text-xl font-semibold text-gray-900">Ask about this forecast</h2>
    <p class="mt-1 text-sm text-gray-600">
        Ask a plain-English question about the figures on this page and a local assistant will explain them.
        It runs on this machine (your data stays local), it only ever states figures from your forecast, and it
        can’t change your plan or build anything — it just explains.
    </p>

    <div class="mt-4 space-y-3" aria-live="polite" aria-atomic="false">
        @forelse ($messages as $m)
            @if ($m['role'] === 'user')
                <div class="flex justify-end">
                    <p class="max-w-[85%] rounded-lg bg-blue-600 px-3 py-2 text-sm text-white">{{ $m['text'] }}</p>
                </div>
            @elseif ($m['status'] === 'answered')
                <div class="flex justify-start">
                    <div class="max-w-[85%] whitespace-pre-line rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-900">{{ $m['text'] }}</div>
                </div>
            @else
                {{-- unavailable / ungrounded_refused / phrasing_refused — a visible, explained non-answer --}}
                <div class="flex justify-start" role="alert">
                    <div class="max-w-[85%] whitespace-pre-line rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ $m['text'] }}</div>
                </div>
            @endif
        @empty
            <p class="text-sm text-gray-500">
                For example: “Does my money last, and until when?”, “How much spendable money is left at the end?”,
                or “What happens to the essentials in the plan?”
            </p>
        @endforelse

        <div wire:loading wire:target="ask" class="flex justify-start">
            <p class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-500">Thinking…</p>
        </div>
    </div>

    <form wire:submit="ask" class="mt-4 flex flex-wrap items-start gap-2">
        <label for="assistant-question" class="sr-only">Your question about this forecast</label>
        <input
            id="assistant-question"
            type="text"
            wire:model="question"
            wire:loading.attr="disabled"
            wire:target="ask"
            placeholder="Ask a question about these figures…"
            class="min-w-0 flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
            autocomplete="off"
        >
        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="ask"
            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
        >
            <span wire:loading.remove wire:target="ask">Ask</span>
            <span wire:loading wire:target="ask">Asking…</span>
        </button>
    </form>

    <p class="mt-2 text-xs text-gray-500">
        Guidance and explanation only — not a personal recommendation or regulated advice. For free, impartial
        guidance, see Pension Wise and MoneyHelper (linked below).
    </p>
</section>
