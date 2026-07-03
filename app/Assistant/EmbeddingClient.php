<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * A local embedding model the methodology retriever talks to (Phase 2 doc-RAG). Abstracted for
 * the same reason as {@see ChatClient}: the pure pieces ({@see DocIndex} search, {@see MethodologyRetriever})
 * can be unit-tested with a fake, never depending on a running model.
 *
 * Implementations must be LOCAL only — the docs are indexed on this machine and nothing leaves it —
 * and must fail loudly: an unreachable model throws {@see AssistantUnavailable} rather than returning
 * an empty or fabricated vector (no silent failure).
 */
interface EmbeddingClient
{
    /** Whether the local runtime is reachable right now (a cheap probe, not a generation). */
    public function isAvailable(): bool;

    /**
     * Embed a single piece of text into a dense vector.
     *
     * @return list<float>
     *
     * @throws AssistantUnavailable when the local model cannot be reached or errors
     */
    public function embed(string $text): array;

    /**
     * Embed several texts in one request (indexing efficiency).
     *
     * @param  list<string>  $texts
     * @return list<list<float>> one vector per input, in order
     *
     * @throws AssistantUnavailable when the local model cannot be reached or errors
     */
    public function embedBatch(array $texts): array;
}
