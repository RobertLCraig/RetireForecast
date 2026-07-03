<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * Guardrail G1 — figure grounding. The assistant's whole credibility is that every number it
 * says is engine-derived; a model that transposes or invents a figure ("lasts to 2058" when
 * the engine said 2054) would be corrosive here in a way it would not be in a generic app.
 *
 * So after the model answers, every MATERIAL figure in its reply — currency amounts, calendar
 * years and percentages, the numbers whose corruption would matter — must appear in the
 * context it was given (or in the reader's own question). Anything else is a figure the model
 * produced on its own, and {@see AssistantService} refuses rather than show it.
 *
 * The check is deliberately strict (no re-rounding: £154,600 is grounded, "about £155,000" is
 * not), because the tool's promise is penny-accuracy. Bare small integers ("your 2 pensions")
 * are not policed — they carry no figure risk.
 */
final class FigureGrounding
{
    /** Percentages within this many points of a grounded one count as the same figure. */
    private const PERCENT_TOLERANCE = 0.5;

    /**
     * The material figures in $answer that are not present in $context or $question.
     * Empty means every figure the model stated is grounded.
     *
     * @return list<string> the ungrounded figures, as they appeared, for the warning
     */
    public static function ungrounded(string $answer, string $context, string $question): array
    {
        $allowed = self::figures($context.' '.$question);
        $ungrounded = [];

        foreach (self::figures($answer) as $figure) {
            if (! self::isAllowed($figure, $allowed)) {
                // Append (never key by the raw string): a numeric-string array key like "2099"
                // would be silently cast to an int, changing the returned type.
                $ungrounded[] = $figure['raw'];
            }
        }

        return array_values(array_unique($ungrounded));
    }

    /**
     * Extract material figures from text.
     *
     * @return list<array{type: string, value: float, raw: string}>
     */
    private static function figures(string $text): array
    {
        $out = [];

        // Currency: £ 1,234.56 with an optional k/m magnitude suffix.
        if (preg_match_all('/£\s?(\d[\d,]*(?:\.\d+)?)\s?([kmKM])?/u', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $out[] = ['type' => 'currency', 'value' => self::scale($m[1], $m[2] ?? ''), 'raw' => trim($m[0])];
            }
        }

        // Grouped bare numbers (154,600) — a currency magnitude stated without the £ sign.
        if (preg_match_all('/(?<![£\d.])(\d{1,3}(?:,\d{3})+(?:\.\d+)?)/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $out[] = ['type' => 'currency', 'value' => self::scale($m[1], ''), 'raw' => trim($m[0])];
            }
        }

        // Percentages.
        if (preg_match_all('/(\d+(?:\.\d+)?)\s?%/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $out[] = ['type' => 'percent', 'value' => (float) $m[1], 'raw' => trim($m[0])];
            }
        }

        // Calendar years (19xx / 20xx) not part of a larger number or a £ amount.
        if (preg_match_all('/(?<![£\d,.])((?:19|20)\d{2})(?!\s?%)/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $out[] = ['type' => 'year', 'value' => (float) $m[1], 'raw' => $m[1]];
            }
        }

        return $out;
    }

    private static function scale(string $number, string $suffix): float
    {
        $value = (float) str_replace(',', '', $number);

        return match (strtolower($suffix)) {
            'k' => $value * 1_000,
            'm' => $value * 1_000_000,
            default => $value,
        };
    }

    /**
     * @param  array{type: string, value: float, raw: string}  $figure
     * @param  list<array{type: string, value: float, raw: string}>  $allowed
     */
    private static function isAllowed(array $figure, array $allowed): bool
    {
        foreach ($allowed as $a) {
            if ($a['type'] !== $figure['type']) {
                continue;
            }
            $tolerance = $figure['type'] === 'percent' ? self::PERCENT_TOLERANCE : 0.5;
            if (abs($a['value'] - $figure['value']) <= $tolerance) {
                return true;
            }
        }

        return false;
    }
}
