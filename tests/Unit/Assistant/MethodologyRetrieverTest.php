<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Assistant\DocChunk;
use App\Assistant\DocIndex;
use App\Assistant\MethodologyRetriever;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeEmbeddingClient;

/**
 * The retriever embeds a question, cosine-searches the doc index, and returns the relevant
 * methodology as a labelled block — or nothing. These pin the two disciplines that keep it safe:
 * a relevant question surfaces the right section with its source, and everything else (an
 * off-topic question, an empty index, an unreachable embedder) returns '' so no doc noise reaches
 * the prompt or the grounding allow-list, and a scenario answer is never broken by methodology.
 */
final class MethodologyRetrieverTest extends TestCase
{
    private function index(): DocIndex
    {
        return new DocIndex([
            new DocChunk('PLAN.md', 'Emergency tax', 'Month one applies emergency tax, over-deducted then reclaimed.', [1.0, 0.0, 0.0]),
            new DocChunk('MORTALITY.md', 'ONS data', 'We use ONS 2020-based cohort life tables.', [0.0, 1.0, 0.0]),
        ]);
    }

    public function test_a_relevant_question_returns_the_matching_section_with_its_source(): void
    {
        $embedder = new FakeEmbeddingClient(
            vectors: ['emergency' => [1.0, 0.0, 0.0]],
            default: [0.0, 0.0, 1.0],
        );
        $retriever = new MethodologyRetriever($embedder, $this->index(), k: 4, threshold: 0.5);

        $block = $retriever->retrieve('how is emergency tax modelled?');

        $this->assertStringContainsString('METHODOLOGY', $block);
        $this->assertStringContainsString('PLAN.md', $block);
        $this->assertStringContainsString('over-deducted then reclaimed', $block);
        // The unrelated mortality section is not pulled in.
        $this->assertStringNotContainsString('cohort life tables', $block);
    }

    public function test_an_off_topic_question_retrieves_nothing(): void
    {
        // The default vector is orthogonal to every chunk, so nothing clears the threshold.
        $embedder = new FakeEmbeddingClient(default: [0.0, 0.0, 1.0]);
        $retriever = new MethodologyRetriever($embedder, $this->index(), k: 4, threshold: 0.5);

        $this->assertSame('', $retriever->retrieve('how much money do I have left?'));
    }

    public function test_an_empty_index_retrieves_nothing(): void
    {
        $embedder = new FakeEmbeddingClient(default: [1.0, 0.0, 0.0]);
        $retriever = new MethodologyRetriever($embedder, new DocIndex([]), k: 4, threshold: 0.5);

        $this->assertSame('', $retriever->retrieve('anything'));
    }

    public function test_an_unreachable_embedder_degrades_to_nothing(): void
    {
        $embedder = new FakeEmbeddingClient(throw: true);
        $retriever = new MethodologyRetriever($embedder, $this->index(), k: 4, threshold: 0.5);

        // No exception escapes: methodology is additive, so a down embedder just means no block.
        $this->assertSame('', $retriever->retrieve('how is emergency tax modelled?'));
    }
}
