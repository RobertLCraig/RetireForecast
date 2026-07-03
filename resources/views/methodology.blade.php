{{-- The methodology page: rendered from docs/METHODOLOGY.md (the same source the local assistant's
     doc-RAG indexes, so page and assistant answer from one set of words). Trusted, static content we
     author, so the rendered HTML is printed unescaped inside a scoped prose wrapper. --}}
<x-layouts.app title="How it works">
    <div class="mx-auto max-w-3xl">
        <nav class="mb-6 text-sm">
            <a href="{{ url('/') }}" class="text-blue-700 underline">Home</a>
        </nav>

        <article class="methodology-prose">
            {!! $html !!}
        </article>
    </div>
</x-layouts.app>
