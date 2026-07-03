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

    // Phase 2 (methodology doc-RAG) embedding model. Unused until Phase 2 lands.
    'embed_model' => env('ASSISTANT_EMBED_MODEL', 'nomic-embed-text'),

];
