<?php

declare(strict_types=1);

namespace App\Import;

use App\Forecast\HouseholdAssembler;

/**
 * Parsing and formatting of pounds-and-pence as exact integer pence — the same
 * "no float in money" rule the {@see HouseholdAssembler} holds at the
 * form boundary, applied here at the import boundary. Imported line items are summed
 * in integer pence and only formatted back to a 2dp string for the form.
 */
final class MoneyText
{
    /** Parse a decimal pounds string (tolerating £, spaces and thousands commas) to exact pence. */
    public static function toPence(string $value): int
    {
        $value = self::clean($value);
        if ($value === '' || $value === '-') {
            return 0;
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);

        $pence = (int) ($whole === '' ? '0' : $whole) * 100 + (int) ($fraction === '' ? '0' : $fraction);

        return $negative ? -$pence : $pence;
    }

    /** Format exact pence back to a plain "pounds.pence" string for the form fields. */
    public static function fromPence(int $pence): string
    {
        $negative = $pence < 0;
        $pence = abs($pence);

        return ($negative ? '-' : '').intdiv($pence, 100).'.'.str_pad((string) ($pence % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Whether a cell looks like a money amount we can parse (after stripping £, spaces, commas). */
    public static function looksNumeric(string $value): bool
    {
        $value = self::clean($value);

        return $value !== '' && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1;
    }

    private static function clean(string $value): string
    {
        // Strip currency symbols and grouping so a $/£/€-formatted cell parses as a number.
        return str_replace([',', '£', '$', '€', ' ', "\t"], '', trim($value));
    }
}
