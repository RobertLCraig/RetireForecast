<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use App\Import\ImportException;
use App\Import\Profiles\PayAndExpenditures;
use App\Import\Spreadsheet;
use Tests\TestCase;

class PayAndExpendituresTest extends TestCase
{
    /** A synthetic workbook in the shape of the personal one (no real figures). */
    private function workbook(): Spreadsheet
    {
        return new Spreadsheet([
            'RC Mortgage Rates' => [['Max purchase price', 'Loan amount']], // a non-scenario tab to skip
            'Demo Test Gate' => [
                ['', 'Yearly', 'Monthly'],
                ['Person Pension DLA', '11772', '981'],
                ['Person Yearly Salary', '30000', '2500'],   // column B is the yearly figure
                ['Total Pay', '41772', '3481'],
                [],
                // Decoy deductions header: reuses "Expenditure Item" / "Deduction Amount"
                // but has only "% of Total Pay" — must NOT be picked as the expenditure block.
                ['Expenditure Item', 'Deduction Amount', '% of Total Pay', '', 'Item', 'Cost'],
                ['P.A.Y.E', '250'],
                ['Mum Take home', '900'],            // decoy: label contains "take home"
                ['Combined Take home Pay', '1500'],  // decoy: label contains "take home"
                [],
                // The real expenditure header — the only one with "% of Take Home Pay".
                ['Expenditure Item', 'Deduction Amount', '% of Total Pay', '% of Take Home Pay', 'Notes'],
                ['Mortgage', '1000'],
                ['Council Tax', '167'],
                ['Netflix', '15'],
                ['Total', '1182'],
                ['Remainder', '0'],
            ],
        ]);
    }

    public function test_it_sums_the_monthly_expenditure_list_into_essential_spend(): void
    {
        $result = (new PayAndExpenditures)->parse($this->workbook());

        // (1000 + 167 + 15) * 12 = 14184; the Total/Remainder rows are excluded.
        $this->assertSame('14184.00', $result->expense['essential']);
    }

    public function test_it_reads_the_yearly_salary_as_gross(): void
    {
        $result = (new PayAndExpenditures)->parse($this->workbook());

        $this->assertSame('30000.00', $result->salaryAnnual);
    }

    public function test_it_names_the_tab_it_used(): void
    {
        $result = (new PayAndExpenditures)->parse($this->workbook());

        $this->assertNotEmpty(array_filter($result->notes, fn ($n): bool => str_contains($n, 'Demo Test Gate')));
    }

    public function test_it_refuses_a_workbook_with_no_expenditure_tab(): void
    {
        $sheet = new Spreadsheet(['Random' => [['nothing', 'here']]]);

        $this->expectException(ImportException::class);
        (new PayAndExpenditures)->parse($sheet);
    }
}
