<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * Guardrail C1 — input grounding, the inverse of {@see FigureGrounding}. G1 stops the model
 * inventing a figure in an ANSWER; this stops it inventing one in an EDIT, which would be
 * worse: an invented input is forecast on, and comes back wearing the engine's authority.
 *
 * So every figure a proposed edit carries must be one the reader actually said. A value the
 * model produced by itself — a rounding, an inference, a "sensible" default, an arithmetic
 * step it took on the reader's behalf — is rejected and re-asked. Matching is exact once
 * £ signs, commas, percent signs and a k/m magnitude suffix are resolved, so "£32k" grounds
 * 32000 but "about £33,000" grounds nothing.
 *
 * Only figures are policed. A non-numeric value is a pick from the app's own closed menu of
 * options ({@see EditTarget::$options}), so it carries no figure risk and C2 already bounds it.
 */
final class ScenarioEditGrounding
{
    /** Whether $value is a figure the reader stated in $readerText (or is not a figure at all). */
    public static function isStated(string $value, string $readerText): bool
    {
        if (! is_numeric($value)) {
            return true;
        }

        foreach (self::figures($readerText) as $stated) {
            if (abs($stated - (float) $value) < 0.0001) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every number in the reader's own words, with commas dropped and a k/m magnitude
     * suffix resolved ("£32k" => 32000.0).
     *
     * @return list<float>
     */
    private static function figures(string $text): array
    {
        if (! preg_match_all('/(\d[\d,]*(?:\.\d+)?)\s?([kmKM])?/u', $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $figures = [];
        foreach ($matches as $match) {
            $value = (float) str_replace(',', '', $match[1]);
            $figures[] = match (strtolower($match[2] ?? '')) {
                'k' => $value * 1_000,
                'm' => $value * 1_000_000,
                default => $value,
            };
        }

        return $figures;
    }
}
