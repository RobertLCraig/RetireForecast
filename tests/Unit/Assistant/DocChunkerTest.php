<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Assistant\DocChunker;
use PHPUnit\Framework\TestCase;

/**
 * The chunker turns a methodology doc into heading-anchored slices for the doc-RAG index. These
 * pin that each heading opens a chunk carrying its full trail (so a retrieved slice knows where it
 * sits), the source is recorded (so an answer can attribute it), and an over-long section splits
 * rather than embedding a whole doc into one blurry vector.
 */
final class DocChunkerTest extends TestCase
{
    public function test_each_heading_opens_a_chunk_carrying_its_trail(): void
    {
        $markdown = <<<'MD'
        # Mortality

        We model longevity from cohort life tables.

        ## ONS cohort data

        We use ONS 2020-based cohort life tables for England and Wales.

        ## Care costs

        Care is modelled as a late-life spell.
        MD;

        $chunks = DocChunker::chunk('MORTALITY.md', $markdown);

        $this->assertCount(3, $chunks);

        $this->assertSame('MORTALITY.md', $chunks[0]->source);
        $this->assertSame('Mortality', $chunks[0]->heading);
        $this->assertStringContainsString('cohort life tables', $chunks[0]->text);

        $this->assertSame('Mortality › ONS cohort data', $chunks[1]->heading);
        $this->assertStringContainsString('ONS 2020-based', $chunks[1]->text);

        $this->assertSame('Mortality › Care costs', $chunks[2]->heading);
        $this->assertStringContainsString('late-life spell', $chunks[2]->text);
    }

    public function test_the_embed_text_prepends_the_heading_trail(): void
    {
        $chunks = DocChunker::chunk('X.md', "# Tax\n\nEmergency tax is over-deducted on month one.");

        $this->assertStringStartsWith('Tax', $chunks[0]->embedText());
        $this->assertStringContainsString('over-deducted', $chunks[0]->embedText());
    }

    public function test_a_long_section_splits_into_several_chunks_under_the_same_heading(): void
    {
        $paragraph = trim(str_repeat('word ', 80));
        $markdown = "## Big section\n\n".implode("\n\n", array_fill(0, 4, $paragraph));

        $chunks = DocChunker::chunk('X.md', $markdown);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertSame('Big section', $chunk->heading);
        }
    }

    public function test_a_bare_heading_with_no_real_body_is_dropped(): void
    {
        $chunks = DocChunker::chunk('X.md', "# Title\n\n## Empty\n\n# Real\n\nThis section has actual content to index.");

        // "Title" and "Empty" carry no meaningful body; only the "Real" section survives.
        $this->assertCount(1, $chunks);
        $this->assertSame('Real', $chunks[0]->heading);
    }
}
