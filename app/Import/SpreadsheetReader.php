<?php

declare(strict_types=1);

namespace App\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Loads an uploaded file into a {@see Spreadsheet}. CSV is read directly; XLSX/XLS go
 * through PhpSpreadsheet in data-only mode, reading the values Excel last cached (no
 * recalculation, so unsupported formulas don't break the import — the cell just reads
 * as its stored value). An unreadable file is reported, never swallowed.
 */
final class SpreadsheetReader
{
    private const CSV_EXTENSIONS = ['csv', 'txt'];

    public function read(string $path, string $filename): Spreadsheet
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($ext === '' || in_array($ext, self::CSV_EXTENSIONS, true)) {
            return Spreadsheet::fromCsv((string) file_get_contents($path));
        }

        return $this->readWorkbook($path);
    }

    private function readWorkbook(string $path): Spreadsheet
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (Throwable $e) {
            throw new ImportException('Could not read the spreadsheet file: '.$e->getMessage());
        }

        $sheets = [];
        foreach ($book->getAllSheets() as $worksheet) {
            $rows = [];
            foreach ($worksheet->getRowIterator() as $row) {
                $cells = [];
                $iterator = $row->getCellIterator();
                $iterator->setIterateOnlyExistingCells(false);
                foreach ($iterator as $cell) {
                    $value = $cell->getValue();
                    if (is_string($value) && str_starts_with($value, '=')) {
                        $value = $cell->getOldCalculatedValue(); // the value Excel cached
                    }
                    $cells[] = $value === null ? '' : (string) $value;
                }
                $rows[] = $cells;
            }
            $sheets[$worksheet->getTitle()] = $rows;
        }

        return new Spreadsheet($sheets);
    }
}
