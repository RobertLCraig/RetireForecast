<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use App\Import\ImportException;
use App\Import\MoneyText;
use App\Import\Profiles\RetireForecastTemplate;
use App\Import\Spreadsheet;
use Tests\TestCase;

class RetireForecastTemplateTest extends TestCase
{
    public function test_money_text_parses_and_formats_as_exact_pence(): void
    {
        $this->assertSame(150000, MoneyText::toPence('1500.00'));
        $this->assertSame(250000, MoneyText::toPence('£2,500.00'));
        $this->assertSame(2000, MoneyText::toPence('20'));
        $this->assertSame('20400.00', MoneyText::fromPence(2040000));
        $this->assertSame('0.05', MoneyText::fromPence(5));
        $this->assertFalse(MoneyText::looksNumeric('abc'));
        $this->assertTrue(MoneyText::looksNumeric('1,234.50'));
    }

    public function test_it_sums_monthly_line_items_to_annual_figures(): void
    {
        $csv = <<<'CSV'
        section,label,monthly_amount
        essential,Mortgage,1500.00
        essential,Council Tax,200.00
        discretionary,Netflix,15.00
        salary,Gross salary,2500.00
        savings,Pension contribution,100.00
        CSV;

        $result = (new RetireForecastTemplate)->parse(Spreadsheet::fromCsv($csv));

        // (1500.00 + 200.00) * 12 = 20400.00; 15.00 * 12 = 180.00; 2500.00 * 12 = 30000.00
        $this->assertSame('20400.00', $result->expense['essential']);
        $this->assertSame('180.00', $result->expense['discretionary']);
        $this->assertSame('30000.00', $result->salaryAnnual);
        $this->assertCount(3, $result->filled);
        $this->assertNotEmpty($result->missing); // a budget never completes the household
    }

    public function test_it_rejects_a_non_numeric_amount(): void
    {
        $csv = "section,label,monthly_amount\nessential,Mortgage,not-a-number\n";

        $this->expectException(ImportException::class);
        (new RetireForecastTemplate)->parse(Spreadsheet::fromCsv($csv));
    }

    public function test_it_rejects_a_file_with_no_recognised_rows(): void
    {
        $csv = "section,label,monthly_amount\nsavings,Pension,100\nunknown,Thing,50\n";

        $this->expectException(ImportException::class);
        (new RetireForecastTemplate)->parse(Spreadsheet::fromCsv($csv));
    }
}
