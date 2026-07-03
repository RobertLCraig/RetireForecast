<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * One retrievable slice of a methodology doc: the source file, the heading trail it sits under,
 * its body text, and (once indexed) the embedding vector that text was encoded to. A readonly
 * value object, the doc-RAG counterpart of {@see AssistantFact} — the unit the {@see DocIndex}
 * ranks and the {@see MethodologyRetriever} shows, with its source so an answer can attribute
 * "from ASSUMPTIONS.md" rather than assert methodology from nowhere.
 */
final class DocChunk
{
    /**
     * @param  list<float>  $embedding  the encoded vector; empty until the chunk is indexed
     */
    public function __construct(
        public readonly string $source,
        public readonly string $heading,
        public readonly string $text,
        public readonly array $embedding = [],
    ) {}

    /**
     * The text actually embedded and searched over: the heading trail prepended to the body, so
     * the vector captures which section this is ("Mortality › ONS cohort data" + the paragraph),
     * not just loose prose. The same string is what a query is matched against.
     */
    public function embedText(): string
    {
        return $this->heading === '' ? $this->text : $this->heading."\n".$this->text;
    }

    /**
     * @param  list<float>  $embedding
     */
    public function withEmbedding(array $embedding): self
    {
        return new self($this->source, $this->heading, $this->text, array_values($embedding));
    }

    /**
     * @return array{source: string, heading: string, text: string, embedding: list<float>}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'heading' => $this->heading,
            'text' => $this->text,
            'embedding' => $this->embedding,
        ];
    }

    /**
     * @param  array{source?: string, heading?: string, text?: string, embedding?: list<float>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['source'] ?? ''),
            (string) ($data['heading'] ?? ''),
            (string) ($data['text'] ?? ''),
            array_map(static fn ($v): float => (float) $v, $data['embedding'] ?? []),
        );
    }
}
