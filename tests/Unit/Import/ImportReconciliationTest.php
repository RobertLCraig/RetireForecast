<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use App\Import\Profiles\ConsciousSpendingPlan;
use App\Import\Profiles\PayAndExpenditures;
use App\Import\Profiles\RetireForecastTemplate;
use Tests\Fixtures\Import\GoldenWorkbooks;
use Tests\TestCase;

/**
 * Data-layer integrity guardrail (DECISIONS 2026-06-25). Each importer is run over a
 * "sanitised real-file golden fixture" — a layout-faithful copy of the real workbook with
 * fake figures — and the value it computes is reconciled against the figure the SHEET
 * ITSELF states (its own TOTAL row). The two must agree.
 *
 * This is the independent cross-check a synthetic happy-path test cannot give: it catches
 * the same-quantity-counted-twice / mis-bucketed class of bug (e.g. a per-bucket TOTAL row
 * summed on top of its line items, or a balance-sheet figure counted as spending).
 */
class ImportReconciliationTest extends TestCase
{
    public function test_pay_and_expenditures_essential_reconciles_to_the_sheet_total(): void
    {
        $result = (new PayAndExpenditures)->parse(GoldenWorkbooks::payAndExpenditures());

        // The importer's essential == the sheet's own "Total" row, annualised — neither the
        // decoy deductions block (Mum Take home / Combined Take home Pay) nor the Total /
        // Remainder rows may inflate it.
        $this->assertSame(GoldenWorkbooks::PAYEXP_ESSENTIAL_ANNUAL, $result->expense['essential']);
        $this->assertSame(
            GoldenWorkbooks::PAYEXP_MONTHLY_TOTAL * 12 * 100,
            (int) round((float) $result->expense['essential'] * 100),
            'Essential must equal the stated monthly Total × 12 — a mismatch means a row was double-counted or the decoy block leaked in.'
        );

        // The income block maps across without inflation.
        $this->assertSame(GoldenWorkbooks::PAYEXP_SALARY_ANNUAL, $result->salaryAnnual);
        $this->assertSame('state', $result->pensions[0]['subtype']);
        $this->assertSame(GoldenWorkbooks::PAYEXP_STATE_PENSION_WEEKLY, $result->pensions[0]['weeklyForecast']);

        $gross = array_map(fn (array $s): string => $s['grossAnnual'], $result->incomeStreams);
        $this->assertContains(GoldenWorkbooks::PAYEXP_DLA_ANNUAL, $gross);
        $this->assertContains(GoldenWorkbooks::PAYEXP_PARTNER_PENSION_ANNUAL, $gross);
    }

    public function test_retireforecast_sections_do_not_bleed_into_each_other(): void
    {
        $result = (new RetireForecastTemplate)->parse(GoldenWorkbooks::retireForecastCsv());

        $this->assertSame(GoldenWorkbooks::RF_ESSENTIAL_ANNUAL, $result->expense['essential']);
        $this->assertSame(GoldenWorkbooks::RF_DISCRETIONARY_ANNUAL, $result->expense['discretionary']);
        $this->assertSame(GoldenWorkbooks::RF_SALARY_ANNUAL, $result->salaryAnnual);

        // The ignored `savings` row (300/mo) must not have leaked into any spending bucket.
        $this->assertStringNotContainsString('3600', $result->expense['essential']);
        $this->assertStringNotContainsString('3600', $result->expense['discretionary']);
    }

    public function test_csp_buckets_reconcile_to_their_stated_totals_without_double_counting(): void
    {
        $result = (new ConsciousSpendingPlan)->parse(GoldenWorkbooks::consciousSpendingPlan());

        // Fixed Costs: the bucket's own "FIXED COSTS TOTAL", annualised — NOT the line items
        // PLUS the total (which would be ~2×).
        $this->assertSame(GoldenWorkbooks::CSP_ESSENTIAL_ANNUAL, $result->expense['essential']);
        // Guilt-Free: a TOTAL row with no line items.
        $this->assertSame(GoldenWorkbooks::CSP_DISCRETIONARY_ANNUAL, $result->expense['discretionary']);

        // Investments + Savings TOTALs become the contribution figure shown back in the notes…
        $this->assertNotEmpty(array_filter(
            $result->notes,
            fn (string $n): bool => str_contains($n, GoldenWorkbooks::CSP_CONTRIBUTION_ANNUAL)
        ));

        // …and the NET WORTH balance-sheet figures must never appear in any spending/contribution total.
        $haystack = implode('|', [...array_values($result->expense), ...$result->notes]);
        $this->assertStringNotContainsString((string) GoldenWorkbooks::CSP_NETWORTH_INVESTMENTS, $haystack);
        $this->assertStringNotContainsString((string) GoldenWorkbooks::CSP_NETWORTH_SAVINGS, $haystack);
    }
}
