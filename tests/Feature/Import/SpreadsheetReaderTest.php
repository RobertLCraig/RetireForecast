<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Import\SpreadsheetReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet as PhpSpreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

class SpreadsheetReaderTest extends TestCase
{
    public function test_it_reads_a_csv_file_into_one_sheet(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sr').'.csv';
        file_put_contents($path, "Fixed Costs,,\nRent,1500,Monthly\n");

        $sheet = (new SpreadsheetReader)->read($path, 'budget.csv');
        @unlink($path);

        $this->assertSame(['Rent', '1500', 'Monthly'], $sheet->rows()[1]);
    }

    public function test_it_reads_a_multi_sheet_xlsx_keeping_sheet_names(): void
    {
        $book = new PhpSpreadsheet;
        $book->getActiveSheet()->setTitle('Flat A')->fromArray([['Mortgage', 1500.58], ['Council Tax', 167]]);
        $book->createSheet()->setTitle('Rental B')->fromArray([['Rent', 1485]]);

        $path = tempnam(sys_get_temp_dir(), 'sr').'.xlsx';
        (new XlsxWriter($book))->save($path);

        $sheet = (new SpreadsheetReader)->read($path, 'workbook.xlsx');
        @unlink($path);

        $this->assertSame(['Flat A', 'Rental B'], $sheet->sheetNames());
        $this->assertSame('Mortgage', $sheet->rows('Flat A')[0][0]);
        $this->assertSame('1500.58', $sheet->rows('Flat A')[0][1]);
        $this->assertSame('Rent', $sheet->rows('Rental B')[0][0]);
    }
}
