{{-- Local-model scenario assistant, as a fixed SIDE PANEL (bottom-right), collapsed to a
     launcher until opened — out of the content flow, not a centre panel. Explains THIS forecast
     in plain English; it cannot change the plan, run anything, or build anything. Runs on a local
     model only (data never leaves the machine). Every figure it states is engine-derived and
     re-verified at runtime (App\Assistant\FigureGrounding); a held-back or unreachable reply is
     shown as such, never a silent blank. Inert unless config('assistant.enabled'). CSP-safe:
     all interactivity is Livewire wire: directives, no inline JS. --}}
<div class="fixed bottom-4 right-4 z-40 print:hidden">
    @if (! $open)
        <button
            type="button"
            wire:click="toggle"
            aria-expanded="false"
            class="flex items-center gap-2 rounded-full bg-blue-600 px-4 py-3 text-sm font-medium text-white shadow-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
        >
            <span aria-hidden="true">💬</span>
            Ask about this forecast
        </button>
    @else
        <section
            aria-labelledby="assistant-heading"
            class="flex max-h-[80vh] w-96 max-w-[calc(100vw-2rem)] flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl"
        >
            <header class="flex items-start justify-between gap-2 border-b border-gray-200 p-4">
                <div>
                    <h2 id="assistant-heading" class="text-base font-semibold text-gray-900">Ask about this forecast</h2>
                    <p class="mt-0.5 text-xs text-gray-500">
                        Runs locally on this machine. It only states figures from your forecast and can’t change your plan.
                    </p>
                </div>
                <button
                    type="button"
                    wire:click="toggle"
                    aria-expanded="true"
                    aria-label="Close the assistant"
                    class="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    <span aria-hidden="true" class="text-lg leading-none">&times;</span>
                </button>
            </header>

            <div class="flex-1 space-y-3 overflow-y-auto p-4" aria-live="polite" aria-atomic="false">
                @forelse ($messages as $m)
                    @if ($m['role'] === 'user')
                        <div class="flex justify-end">
                            <p class="max-w-[85%] rounded-lg bg-blue-600 px-3 py-2 text-sm text-white">{{ $m['text'] }}</p>
                        </div>
                    @elseif ($m['status'] === 'answered')
                        <div class="flex justify-start">
                            <div class="max-w-[90%] whitespace-pre-line rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-900">{{ $m['text'] }}</div>
                        </div>
                    @else
                        {{-- unavailable / ungrounded_refused / phrasing_refused — a visible, explained non-answer --}}
                        <div class="flex justify-start" role="alert">
                            <div class="max-w-[90%] whitespace-pre-line rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ $m['text'] }}</div>
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

            <form wire:submit="ask" class="border-t border-gray-200 p-3">
                <label for="assistant-question" class="sr-only">Your question about this forecast</label>
                <div class="flex items-center gap-2">
                    <input
                        id="assistant-question"
                        type="text"
                        wire:model="question"
                        wire:loading.attr="disabled"
                        wire:target="ask"
                        placeholder="Ask a question…"
                        class="min-w-0 flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                        autocomplete="off"
                    >
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="ask"
                        class="shrink-0 rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="ask">Ask</span>
                        <span wire:loading wire:target="ask">…</span>
                    </button>
                </div>
                <p class="mt-2 text-xs text-gray-400">
                    Explanation only — not a personal recommendation or regulated advice. See Pension Wise / MoneyHelper.
                </p>
            </form>
        </section>
    @endif
</div>
