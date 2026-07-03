<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * The in-memory methodology index: the indexed {@see DocChunk}s and a cosine-similarity search
 * over them. Pure — no I/O, no model — so the ranking is unit-testable with hand-built vectors;
 * the loading/persisting of the vectors lives in the command and the retriever, not here.
 *
 * The corpus is small (the project's own `docs/`), so a brute-force cosine over a few hundred
 * 768-dim vectors is ample — no vector database, in the same hand-rolled spirit as the engine's
 * integer-pence money. A query returns only chunks scoring above a threshold, so an unrelated
 * (scenario-figure) question retrieves nothing rather than forcing an irrelevant match.
 */
final class DocIndex
{
    /**
     * @param  list<DocChunk>  $chunks
     */
    public function __construct(private readonly array $chunks) {}

    /** @return list<DocChunk> */
    public function chunks(): array
    {
        return $this->chunks;
    }

    public function count(): int
    {
        return count($this->chunks);
    }

    public function isEmpty(): bool
    {
        return $this->chunks === [];
    }

    /**
     * The top chunks whose cosine similarity to $query is at least $threshold, best first,
     * capped at $k. Empty when nothing clears the bar.
     *
     * @param  list<float>  $query
     * @return list<array{chunk: DocChunk, score: float}>
     */
    public function search(array $query, int $k, float $threshold): array
    {
        $queryNorm = self::norm($query);
        if ($queryNorm === 0.0) {
            return [];
        }

        $scored = [];
        foreach ($this->chunks as $chunk) {
            $score = self::cosine($query, $queryNorm, $chunk->embedding);
            if ($score >= $threshold) {
                $scored[] = ['chunk' => $chunk, 'score' => $score];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, max(0, $k));
    }

    /**
     * @param  list<float>  $query
     * @param  list<float>  $vector
     */
    private static function cosine(array $query, float $queryNorm, array $vector): float
    {
        $vectorNorm = self::norm($vector);
        if ($vectorNorm === 0.0) {
            return 0.0;
        }

        $dot = 0.0;
        $n = min(count($query), count($vector));
        for ($i = 0; $i < $n; $i++) {
            $dot += $query[$i] * $vector[$i];
        }

        return $dot / ($queryNorm * $vectorNorm);
    }

    /**
     * @param  list<float>  $vector
     */
    private static function norm(array $vector): float
    {
        $sum = 0.0;
        foreach ($vector as $v) {
            $sum += $v * $v;
        }

        return sqrt($sum);
    }

    /**
     * @return list<array{source: string, heading: string, text: string, embedding: list<float>}>
     */
    public function toArray(): array
    {
        return array_map(static fn (DocChunk $c): array => $c->toArray(), $this->chunks);
    }

    /**
     * @param  list<array{source?: string, heading?: string, text?: string, embedding?: list<float>}>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_values(array_map(
            static fn (array $c): DocChunk => DocChunk::fromArray($c),
            $data,
        )));
    }
}
