<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Local-model scenario assistant
    |--------------------------------------------------------------------------
    |
    | The in-app "chatbot" that explains a forecast in plain English. It runs on a
    | LOCAL model only (Ollama on this machine): the scenario is a real household's
    | financial data and must never leave the machine — the same rule the document
    | import investigation settled (DI-7). Nothing here reaches a cloud endpoint.
    |
    | The model is NEVER the source of a number. Every figure it may state is drawn
    | from the engine (App\Assistant\ScenarioContext) and re-checked at runtime
    | (App\Assistant\FigureGrounding, guardrail G1); recommendation phrasing is held
    | to the same partition as the rest of the app (App\Compliance\OutputPhrasing,
    | guardrail G2). And the model NEVER builds: its only future write is a backlog
    | append (Phase 3). See docs/RESEARCH-local-assistant.md + DECISIONS 2026-07-03.
    |
    */

    // Master switch. Off by default: the feature is inert until the local runtime is
    // deliberately wired up, so a machine without Ollama shows nothing (no silent errors).
    'enabled' => env('ASSISTANT_ENABLED', false),

    // The local Ollama endpoint. Localhost only — do not point this at a remote host;
    // that would exfiltrate the household's financial data (guardrail: local-only).
    'base_url' => env('ASSISTANT_BASE_URL', 'http://localhost:11434'),

    // The chat model. A tool-following instruct/reasoning model is best; the choice is a
    // runtime one (like the assumption sets), never baked into the code.
    'model' => env('ASSISTANT_MODEL', 'qwen3:14b'),

    // Seconds to wait for a generation. A 14B model on CPU can be slow, so allow headroom.
    'timeout' => (int) env('ASSISTANT_TIMEOUT', 120),

    // Seconds to wait when probing whether the runtime is up (isAvailable) — short, so an
    // absent Ollama fails fast to a clear "not running" message rather than hanging the page.
    'probe_timeout' => (int) env('ASSISTANT_PROBE_TIMEOUT', 2),

    // Phase 2 — methodology doc-RAG (the "how does it model X?" questions).
    //
    // The local embedding model used both to index the docs and to embed a question at query time.
    'embed_model' => env('ASSISTANT_EMBED_MODEL', 'nomic-embed-text'),

    // Where the docs to index live, and where the built vector index is written. The index sits under
    // storage/app/private (gitignored) — it is a derived build artifact, not committed. The `.local.md`
    // docs (real household PII) are NEVER indexed; the builder excludes them by rule.
    'docs_path' => env('ASSISTANT_DOCS_PATH', base_path('docs')),
    'doc_index_path' => env('ASSISTANT_DOC_INDEX_PATH', storage_path('app/private/assistant/doc-index.json')),

    // The methodology-bearing docs to index — CURATED, not the whole folder. Most of docs/ is internal
    // PLANNING and build-record prose (PLAN*, RESEARCH-competitive/delta/import…), which is NOT user-facing
    // methodology: indexing it makes the assistant surface build-status noise ("DrawdownStrategy enum, both
    // shipped") as if it were an explanation — a trust regression. So the index is limited to the docs that
    // genuinely explain how the tool models things and where its sourced figures come from. METHODOLOGY.md is
    // the purpose-written engine-computation doc (also the public /methodology page — one source, two homes).
    // Filenames, matched case-insensitively; empty = every *.md (minus *.local.md).
    'methodology_docs' => [
        'METHODOLOGY.md',
        'ASSUMPTIONS.md',
        'MORTALITY.md',
        'RESEARCH-stress-test-and-official-sources.md',
    ],

    // Retrieval shape: how many chunks to attach, and the minimum cosine similarity to attach any. With a
    // curated corpus a scenario-figure question has nothing semantically close, so nothing attaches; the
    // threshold is the backstop. Calibrated against nomic-embed-text (search_query/search_document prefixes);
    // a runtime knob. NB nomic's absolute cosines are compressed (~0.6–0.72), so this sits near that band.
    'retrieval_k' => (int) env('ASSISTANT_RETRIEVAL_K', 4),
    'retrieval_threshold' => (float) env('ASSISTANT_RETRIEVAL_THRESHOLD', 0.66),

];
