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
            'Demo Mortgage Rates' => [['Max purchase price', 'Loan amount']], // a non-scenario tab to skip
            'Demo Test Gate' => [
                ['', 'Yearly', 'Monthly'],
                ['Person Pension DLA', '11772', '981'],                       // -> tax-free income stream
                ['Person Pension SP (State Pension)', '10400', '866'],        // -> state pension (10400/52 = 200/wk)
                ['Person Yearly Salary', '30000', '2500', '', 'Partner Pension', '12000'], // salary (B) + a pension in a later column
                ['Total Pay', '52172', '4347'],
                [],
                // Decoy deductions header: reuses "Expenditure Item" / "Deduction Amount"
                // but has only "% of Total Pay" — must NOT be picked as the expenditure block.
                ['Expenditure Item', 'Deduction Amount', '% of Total Pay', '', 'Item', 'Cost'],
                ['P.A.Y.E', '250'],
                ['Family Take home', '900'],         // decoy: label contains "take home"
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

    public function test_it_maps_the_state_pension_to_a_weekly_forecast(): void
    {
        $result = (new PayAndExpenditures)->parse($this->workbook());

        $this->assertCount(1, $result->pensions);
        $this->assertSame('state', $result->pensions[0]['subtype']);
        $this->assertSame('200.00', $result->pensions[0]['weeklyForecast']); // 10400 / 52
    }

    public function test_it_maps_dla_as_tax_free_income_and_a_partner_pension_as_an_annuity(): void
    {
        $result = (new PayAndExpenditures)->parse($this->workbook());

        $this->assertCount(2, $result->incomeStreams);

        $dla = $result->incomeStreams[0];
        $this->assertSame('11772.00', $dla['grossAnnual']);
        $this->assertFalse($dla['taxable']); // DLA is tax-free
        $this->assertSame('', $dla['startAge']); // no age in the sheet — left for the user

        $partner = $result->incomeStreams[1];
        $this->assertSame('12000.00', $partner['grossAnnual']);
        $this->assertSame('annuity', $partner['type']);
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
