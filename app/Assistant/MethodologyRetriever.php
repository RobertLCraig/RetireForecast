<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * Guardrail-preserving methodology retrieval (Phase 2 doc-RAG). Given a reader's question, it
 * embeds the question, cosine-searches the {@see DocIndex} of the project's own `docs/`, and
 * returns the most relevant methodology slices as a labelled block for the prompt — the "how does
 * it model emergency tax / mortality / the Monte Carlo?" answers the scenario figures alone can't give.
 *
 * Two disciplines make it safe (LA-6): it is retrieval over METHODOLOGY docs only, never the reader's
 * own numbers (those stay in {@see ScenarioContext}); and it is threshold-gated, so a pure
 * scenario-figure question retrieves nothing and adds no doc noise to the prompt or the grounding
 * allow-list. It degrades gracefully: if the index is empty or the local embedder is unreachable it
 * returns '' — the scenario answer still stands, methodology is simply not attached.
 *
 * nomic-embed-text is asymmetric: documents and queries are embedded with different task prefixes.
 * The command indexes chunks with {@see DOCUMENT_PREFIX}; a query is embedded with {@see QUERY_PREFIX}.
 */
final class MethodologyRetriever
{
    public const DOCUMENT_PREFIX = 'search_document: ';

    public const QUERY_PREFIX = 'search_query: ';

    public function __construct(
        private readonly EmbeddingClient $embedder,
        private readonly DocIndex $index,
        private readonly int $k = 4,
        private readonly float $threshold = 0.55,
    ) {}

    /**
     * The methodology block to attach to the prompt for this question, or '' when nothing relevant
     * is found (or the embedder is unavailable). The block is BOTH shown to the model and folded
     * into the grounding source, so a methodology figure it cites (e.g. the personal allowance) is
     * groundable while an invented one is not.
     */
    public function retrieve(string $question): string
    {
        if ($this->index->isEmpty() || trim($question) === '') {
            return '';
        }

        try {
            $queryVector = $this->embedder->embed(self::QUERY_PREFIX.$question);
        } catch (AssistantUnavailable) {
            return '';   // graceful: the chat turn itself will report if the runtime is down
        }

        $hits = $this->index->search($queryVector, $this->k, $this->threshold);
        if ($hits === []) {
            return '';
        }

        $slices = array_map(static function (array $hit): string {
            $chunk = $hit['chunk'];
            $where = $chunk->heading === '' ? $chunk->source : "{$chunk->source} › {$chunk->heading}";

            return "From {$where}:\n{$chunk->text}";
        }, $hits);

        return "METHODOLOGY — how the tool works in general (background, NOT the reader's own figures; "
            ."never quote a figure from here as the reader's own number):\n\n".implode("\n\n", $slices);
    }
}
