<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Assistant\DocChunk;
use App\Assistant\DocIndex;
use PHPUnit\Framework\TestCase;

/**
 * The index ranks methodology chunks by cosine similarity to a query and gates on a threshold, so a
 * relevant "how does it model X?" query surfaces the right section and an unrelated (scenario-figure)
 * query surfaces nothing. Pinned with hand-built orthogonal vectors, so the ranking is proven without
 * a running embedding model.
 */
final class DocIndexTest extends TestCase
{
    private function index(): DocIndex
    {
        return new DocIndex([
            new DocChunk('a.md', 'Tax', 'emergency tax', [1.0, 0.0, 0.0]),
            new DocChunk('b.md', 'Mortality', 'life tables', [0.0, 1.0, 0.0]),
            new DocChunk('c.md', 'Both', 'tax and mortality', [1.0, 1.0, 0.0]),
        ]);
    }

    public function test_search_ranks_by_cosine_and_gates_on_the_threshold(): void
    {
        // Query aligned with the first chunk: exact match 1.0, the "both" chunk 0.707, the second 0.0.
        $hits = $this->index()->search([1.0, 0.0, 0.0], k: 3, threshold: 0.5);

        $this->assertCount(2, $hits);                       // the orthogonal chunk (0.0) is below threshold
        $this->assertSame('a.md', $hits[0]['chunk']->source); // exact match ranks first
        $this->assertSame('c.md', $hits[1]['chunk']->source);
        $this->assertEqualsWithDelta(1.0, $hits[0]['score'], 1e-9);
        $this->assertEqualsWithDelta(0.7071, $hits[1]['score'], 1e-4);
    }

    public function test_a_higher_threshold_admits_only_the_closest(): void
    {
        $hits = $this->index()->search([1.0, 0.0, 0.0], k: 3, threshold: 0.8);

        $this->assertCount(1, $hits);
        $this->assertSame('a.md', $hits[0]['chunk']->source);
    }

    public function test_k_caps_the_number_returned(): void
    {
        $hits = $this->index()->search([1.0, 1.0, 0.0], k: 1, threshold: 0.0);

        $this->assertCount(1, $hits);
        $this->assertSame('c.md', $hits[0]['chunk']->source);  // the "both" chunk is the closest here
    }

    public function test_a_zero_query_vector_matches_nothing(): void
    {
        $this->assertSame([], $this->index()->search([0.0, 0.0, 0.0], k: 3, threshold: 0.0));
    }

    public function test_to_array_and_from_array_round_trip_preserves_chunks_and_vectors(): void
    {
        $restored = DocIndex::fromArray($this->index()->toArray());

        $this->assertSame(3, $restored->count());
        $this->assertSame('emergency tax', $restored->chunks()[0]->text);
        $this->assertSame([1.0, 0.0, 0.0], $restored->chunks()[0]->embedding);
        $this->assertSame('Mortality', $restored->chunks()[1]->heading);
    }
}
