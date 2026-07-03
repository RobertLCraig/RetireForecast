<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * Splits a markdown methodology doc into heading-anchored {@see DocChunk}s for indexing. Pure —
 * no I/O, no container — so it is unit-testable from a plain string.
 *
 * The unit is a heading section: each `#`…`######` heading opens a new chunk, carrying the full
 * heading trail (e.g. "Mortality › ONS cohort data") so a retrieved slice knows where it sits.
 * A long section is split further on paragraph boundaries once it passes a soft word cap, so a
 * chunk stays a focused, embeddable size rather than a whole 90k-word plan in one vector.
 *
 * Markdown noise that would pollute an embedding (heading `#` marks, table pipes, code fences,
 * link URLs) is lightly flattened to its prose; the heading text itself is kept.
 */
final class DocChunker
{
    /** Flush a section into a new chunk once it passes this many words (at a paragraph break). */
    private const SOFT_WORD_CAP = 220;

    /**
     * Hard ceiling on a chunk's words. The soft cap only splits at paragraph breaks, so an
     * unbroken wall of text (a long table, a section with no blank lines) could otherwise exceed
     * the embedding model's context and be rejected. Every chunk is windowed to at most this many
     * words as a safety net.
     */
    private const HARD_WORD_CAP = 350;

    /** Drop a chunk with fewer words than this (a stray heading with no real body). */
    private const MIN_WORDS = 4;

    /**
     * @return list<DocChunk> chunks with empty embeddings (the indexer fills them)
     */
    public static function chunk(string $source, string $markdown): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];

        /** @var array<int, string> $trail  heading text keyed by level (1..6) */
        $trail = [];
        $buffer = [];
        $chunks = [];
        $inFence = false;

        $flush = static function () use (&$buffer, &$chunks, $source, &$trail): void {
            $body = self::cleanBody(implode("\n", $buffer));
            $buffer = [];
            $heading = self::headingTrail($trail);
            foreach (self::windows($body) as $slice) {
                $chunks[] = new DocChunk($source, $heading, $slice);
            }
        };

        foreach ($lines as $line) {
            // Fenced code blocks: keep the content as text but never let ``` toggle a heading.
            if (preg_match('/^\s*```/', $line)) {
                $inFence = ! $inFence;
                $buffer[] = $line;

                continue;
            }

            if (! $inFence && preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                // A new heading closes the current section and opens the next.
                $flush();
                $level = strlen($m[1]);
                $trail[$level] = trim($m[2]);
                foreach (array_keys($trail) as $lvl) {   // deeper headings no longer apply
                    if ($lvl > $level) {
                        unset($trail[$lvl]);
                    }
                }

                continue;
            }

            $buffer[] = $line;

            // Split an over-long section at a paragraph break, keeping the same heading trail.
            if ($line === '' && self::wordCount(implode("\n", $buffer)) >= self::SOFT_WORD_CAP) {
                $flush();
            }
        }

        $flush();

        return $chunks;
    }

    /**
     * @param  array<int, string>  $trail
     */
    private static function headingTrail(array $trail): string
    {
        ksort($trail);

        return implode(' › ', array_filter($trail, static fn (string $h): bool => $h !== ''));
    }

    /** Flatten markdown noise to prose so the embedding encodes meaning, not syntax. */
    private static function cleanBody(string $text): string
    {
        // Markdown links / images -> their visible text.
        $text = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text;
        // Table pipes and list/bold/emphasis markers -> spaces.
        $text = preg_replace('/[|>*`]+/', ' ', $text) ?? $text;
        $text = preg_replace('/^\s*[-+]\s+/m', '', $text) ?? $text;
        // Collapse blank runs and trim.
        $text = preg_replace("/\n{2,}/", "\n", $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Split a body into windows of at most {@see HARD_WORD_CAP} words — the safety net that keeps
     * even an unbroken wall of text within the embedding model's context. A body below
     * {@see MIN_WORDS} (a stray heading) yields nothing.
     *
     * @return list<string>
     */
    private static function windows(string $body): array
    {
        $trimmed = trim($body);
        $words = $trimmed === '' ? [] : (preg_split('/\s+/', $trimmed) ?: []);
        if (count($words) < self::MIN_WORDS) {
            return [];
        }

        return array_map(
            static fn (array $slice): string => implode(' ', $slice),
            array_chunk($words, self::HARD_WORD_CAP),
        );
    }

    private static function wordCount(string $text): int
    {
        $trimmed = trim($text);

        return $trimmed === '' ? 0 : count(preg_split('/\s+/', $trimmed) ?: []);
    }
}
