<?php

declare(strict_types=1);

namespace App\Import;

/**
 * An uploaded spreadsheet reduced to plain string cells, one entry per sheet. This is
 * the single shape an {@see ImportProfile} reads, whichever file format it came from —
 * {@see SpreadsheetReader} builds it from CSV (one unnamed sheet) or XLSX (named sheets).
 * Keeping profiles off the file format means a profile can pick a tab by name (the
 * personal workbook has several) without knowing how it was loaded.
 */
final class Spreadsheet
{
    /** @param array<string, list<list<string>>> $sheets sheetName => rows of string cells */
    public function __construct(private readonly array $sheets) {}

    /** Build from raw CSV text as a single sheet. */
    public static function fromCsv(string $contents): self
    {
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            $rows[] = trim($line) === '' ? [] : str_getcsv($line, ',', '"', '');
        }

        return new self(['' => $rows]);
    }

    /** @return list<string> */
    public function sheetNames(): array
    {
        return array_keys($this->sheets);
    }

    /**
     * The rows of a named sheet, or of the first sheet when no name is given.
     *
     * @return list<list<string>>
     */
    public function rows(?string $sheet = null): array
    {
        if ($sheet === null) {
            return array_values($this->sheets)[0] ?? [];
        }

        return $this->sheets[$sheet] ?? [];
    }

    /** A copy holding only the named sheet (or this, unchanged, if it has no such sheet). */
    public function select(string $name): self
    {
        return isset($this->sheets[$name]) ? new self([$name => $this->sheets[$name]]) : $this;
    }

    /** The first sheet name for which the predicate holds, or null. */
    public function firstSheetMatching(callable $predicate): ?string
    {
        foreach ($this->sheetNames() as $name) {
            if ($predicate($name)) {
                return $name;
            }
        }

        return null;
    }
}
