{{-- Local-model scenario assistant, as a DOCKED full-height side panel on the right edge (not a
     floating chat bubble): collapsed to an edge tab until opened. Explains THIS forecast in plain
     English; it cannot change the plan, run anything, or build anything. Runs on a local model only
     (data never leaves the machine). Every figure it states is engine-derived and re-verified at
     runtime (App\Assistant\FigureGrounding); a held-back or unreachable reply is shown as such, never
     a silent blank. Inert unless config('assistant.enabled'). CSP-safe: all interactivity is Livewire
     wire: directives, no inline JS. --}}
<div class="print:hidden">
    @if (! $open)
        {{-- Docked edge tab (attached to the right edge, not a corner bubble). --}}
        <button
            type="button"
            wire:click="toggle"
            aria-expanded="false"
            class="fixed right-0 top-1/3 z-40 flex items-center gap-2 rounded-l-lg bg-blue-600 py-3 pl-3 pr-2 text-sm font-medium text-white shadow-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
        >
            <span aria-hidden="true">💬</span>
            <span class="hidden sm:inline">Ask about this forecast</span>
        </button>
    @else
        <section
            aria-labelledby="assistant-heading"
            data-assistant-open
            class="fixed inset-y-0 right-0 z-40 flex w-96 max-w-[calc(100vw-1rem)] flex-col border-l border-gray-200 bg-white shadow-xl"
        >
            <header class="flex items-start justify-between gap-2 border-b border-gray-200 p-4">
                <div>
                    <h2 id="assistant-heading" class="text-base font-semibold text-gray-900">Ask about this forecast</h2>
                    <p class="mt-0.5 text-xs text-gray-500">
                        Runs locally on this machine. It only states figures from your forecast and can’t change your plan.
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-1">
                    @if ($tab === 'ask' && $messages !== [])
                        <button
                            type="button"
                            wire:click="clear"
                            class="rounded-md px-2 py-1 text-xs font-medium text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                            Clear
                        </button>
                    @endif
                    <button
                        type="button"
                        wire:click="toggle"
                        aria-expanded="true"
                        aria-label="Close the assistant"
                        class="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                        <span aria-hidden="true" class="text-lg leading-none">&times;</span>
                    </button>
                </div>
            </header>

            {{-- Two views: Ask (explain this forecast) and Ideas (capture backlog items — the model's only write). --}}
            <div class="flex border-b border-gray-200 px-2" role="tablist">
                <button type="button" role="tab" wire:click="switchTab('ask')" @if ($tab === 'ask') aria-selected="true" @endif
                    class="border-b-2 px-3 py-2 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-blue-500 {{ $tab === 'ask' ? 'border-blue-600 text-blue-700' : 'border-transparent text-gray-500 hover:text-gray-800' }}">
                    Ask
                </button>
                <button type="button" role="tab" wire:click="switchTab('ideas')" @if ($tab === 'ideas') aria-selected="true" @endif
                    class="border-b-2 px-3 py-2 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-blue-500 {{ $tab === 'ideas' ? 'border-blue-600 text-blue-700' : 'border-transparent text-gray-500 hover:text-gray-800' }}">
                    Ideas
                </button>
            </div>

            @if ($tab === 'ask')
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
                    {{-- Starting state: grouped starter questions. Clicking one asks it directly. --}}
                    <p class="text-sm text-gray-600">Ask anything about this forecast, or start with one of these:</p>
                    @foreach ($this->suggestions() as $group)
                        @if ($group['questions'] !== [])
                            <div>
                                <p class="mb-1.5 mt-3 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ $group['heading'] }}</p>
                                <div class="space-y-1.5">
                                    @foreach ($group['questions'] as $q)
                                        <button
                                            type="button"
                                            wire:click="ask(@js($q))"
                                            wire:loading.attr="disabled"
                                            wire:target="ask"
                                            class="block w-full rounded-md border border-gray-200 bg-white px-3 py-2 text-left text-sm text-gray-700 hover:border-blue-300 hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        >
                                            {{ $q }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
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
            @else
            {{-- Ideas tab: capture an idea for the tool. The model structures it into a queued item; it
                 never builds it. Append-only, attributed, reversible — a human reviews and promotes elsewhere. --}}
            <div class="flex-1 space-y-3 overflow-y-auto p-4">
                <p class="text-sm text-gray-600">
                    Have an idea for the tool — something to research, a feature, a fix? Jot it down and it goes on a
                    backlog for review. The assistant only captures ideas here; it never builds them.
                </p>

                @if ($captureNotice !== '')
                    <p role="status" class="rounded-md bg-green-50 px-3 py-2 text-sm text-green-800">{{ $captureNotice }}</p>
                @endif

                @forelse ($this->backlogItems() as $item)
                    <div class="flex items-start justify-between gap-2 rounded-md border border-gray-200 p-3">
                        <div class="min-w-0">
                            <span class="inline-block rounded bg-gray-100 px-1.5 py-0.5 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $item->kind->label() }}</span>
                            <p class="mt-1 text-sm font-medium text-gray-900">{{ $item->title }}</p>
                            @if ($item->note)
                                <p class="mt-0.5 text-xs text-gray-600">{{ $item->note }}</p>
                            @endif
                        </div>
                        <button type="button" wire:click="deleteIdea({{ $item->id }})" aria-label="Delete this idea"
                            class="shrink-0 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <span aria-hidden="true" class="text-lg leading-none">&times;</span>
                        </button>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No ideas captured yet.</p>
                @endforelse
            </div>

            <form wire:submit="captureIdea" class="border-t border-gray-200 p-3">
                <label for="assistant-idea" class="sr-only">Your idea for the tool</label>
                <textarea
                    id="assistant-idea"
                    wire:model="idea"
                    wire:loading.attr="disabled"
                    wire:target="captureIdea"
                    rows="2"
                    placeholder="e.g. Could it model equity release?"
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                ></textarea>
                <div class="mt-2 flex items-center justify-between gap-2">
                    <p class="text-xs text-gray-400">Saved for review — not acted on.</p>
                    <button type="submit" wire:loading.attr="disabled" wire:target="captureIdea"
                        class="shrink-0 rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                        <span wire:loading.remove wire:target="captureIdea">Add to backlog</span>
                        <span wire:loading wire:target="captureIdea">Adding…</span>
                    </button>
                </div>
            </form>
            @endif
        </section>
    @endif
</div>
