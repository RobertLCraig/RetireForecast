<?php

declare(strict_types=1);

namespace App\Compliance;

/**
 * The wording guard for the regulatory boundary: education/guidance only, never a
 * personal recommendation. It holds the banned recommendation patterns and scans
 * text for them.
 *
 * The patterns target *directive* phrasing ("you should", "the best option", "is
 * better for you") rather than the bare nouns, so neutral disclaimers that legitimately
 * use the words in negated form ("this does not recommend a course of action", "not a
 * recommendation") are not flagged.
 *
 * Used by the build-time banned-phrasing test (Tests\Feature\Compliance\BannedPhrasingTest),
 * which enforces a *partition*: the neutral result/warning templates, the default formatter
 * and every export must be clean, while directive phrasing is permitted only inside the
 * walled-off {@see Interpretation} layer (the admin-granted, off-by-default advice-style
 * readouts). See DECISIONS 2026-06-25.
 */
final class OutputPhrasing
{
    /**
     * Directive recommendation phrases that may never appear in neutral output.
     * Case-insensitive, word-boundaried regexes.
     *
     * @var list<string>
     */
    public const BANNED = [
        '/\byou should\b/i',
        '/\byou ought to\b/i',
        '/\byou\'d be better off\b/i',
        '/\bwe recommend\b/i',
        '/\bi recommend\b/i',
        '/\brecommend that you\b/i',
        '/\bwe suggest you\b/i',
        '/\bwe advise\b/i',
        '/\bour advice is\b/i',
        '/\bthe best option\b/i',
        '/\bthe best choice\b/i',
        '/\bbest course of action\b/i',
        '/\bis better for you\b/i',
        '/\bbetter for you\b/i',
        '/\bthe right choice for you\b/i',
    ];

    /**
     * The banned phrases found in the given text, in order of first appearance.
     * An empty array means the text is on the guidance side of the line.
     *
     * @return list<string>
     */
    public static function violations(string $text): array
    {
        $found = [];
        foreach (self::BANNED as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                $found[] = $m[0];
            }
        }

        return $found;
    }

    /** Whether the text contains any banned recommendation phrasing. */
    public static function isClean(string $text): bool
    {
        return self::violations($text) === [];
    }
}
