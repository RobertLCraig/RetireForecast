<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * The outcome of one assistant turn. Every outcome is explicit — answered, unavailable, or
 * refused-by-a-guardrail — so the UI never has to guess and never shows a silent blank: an
 * unreachable model, a held-back ungrounded figure and a blocked recommendation each carry
 * their own status and reason (no silent failure).
 */
final class AssistantAnswer
{
    /**
     * @param  list<string>  $warnings  operator-facing reasons (e.g. which figure was blocked)
     */
    private function __construct(
        public readonly string $text,
        public readonly bool $ok,
        public readonly string $status,
        public readonly array $warnings = [],
    ) {}

    public static function answered(string $text): self
    {
        return new self($text, true, 'answered');
    }

    public static function unavailable(string $text): self
    {
        return new self($text, false, 'unavailable');
    }

    /**
     * @param  list<string>  $warnings
     */
    public static function refused(string $text, string $status, array $warnings = []): self
    {
        return new self($text, false, $status, $warnings);
    }
}
