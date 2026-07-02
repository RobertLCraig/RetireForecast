<?php

declare(strict_types=1);

namespace Tests\Fixtures\Import;

use App\Import\Spreadsheet;

/**
 * Sanitised "real-file golden fixtures" — each {@see Spreadsheet} here reproduces the
 * LAYOUT of one of Rob's real workbooks (the trap rows that synthetic happy-path tests
 * don't think to include: decoy headers, per-bucket TOTAL rows, a NET WORTH section,
 * Total/Remainder rows) with the actual figures replaced by round fakes. No real
 * financial data lives here, so these are safe to commit — the real `.xlsx` stay
 * gitignored under docs/.
 *
 * The numbers are chosen self-consistent: each block's line items sum to the block's
 * stated TOTAL, so a reconciliation test can assert "what the importer computed" ==
 * "what the sheet itself states" and catch any double-count or mis-bucketing.
 *
 * Structure captured from the masked dumps of:
 *  - docs/Pay and Expenditures.xlsx  (demo scenario tab)
 *  - docs/IWT Conscious Spending Plan 2023.xlsx  (Example tab)
 */
final class GoldenWorkbooks
{
    // --- Pay & Expenditures (demo scenario tab) -------------------------------------
    /** Monthly sum of the expenditure line items == the sheet's own "Total" row. */
    public const PAYEXP_MONTHLY_TOTAL = 2050;          // 1000+150+200+80+30+400+90+15+85

    public const PAYEXP_ESSENTIAL_ANNUAL = '24600.00'; // 2050 * 12

    public const PAYEXP_SALARY_ANNUAL = '30000.00';

    public const PAYEXP_STATE_PENSION_WEEKLY = '200.00'; // 10400 / 52

    public const PAYEXP_DLA_ANNUAL = '6000.00';

    public const PAYEXP_PARTNER_PENSION_ANNUAL = '12000.00';

    // --- IWT Conscious Spending Plan (Example tab) -----------------------------------
    public const CSP_FIXED_MONTHLY_TOTAL = 3300;        // == sum of the Fixed line items

    public const CSP_ESSENTIAL_ANNUAL = '39600.00';     // 3300 * 12  (NOT doubled)

    public const CSP_GUILT_MONTHLY_TOTAL = 900;

    public const CSP_DISCRETIONARY_ANNUAL = '10800.00'; // 900 * 12

    public const CSP_CONTRIBUTION_ANNUAL = '12000.00';  // (600 + 400) * 12, from the bucket TOTALs

    /** A deliberately wrong Fixed Costs TOTAL — the line items still sum to CSP_FIXED_MONTHLY_TOTAL (3300). */
    public const CSP_FIXED_INCONSISTENT_TOTAL = 9999;

    /** Balance-sheet figures in the NET WORTH section that must NEVER reach a spending bucket. */
    public const CSP_NETWORTH_INVESTMENTS = 200000;

    public const CSP_NETWORTH_SAVINGS = 30000;

    // --- RetireForecast template (CSV) -----------------------------------------------
    public const RF_ESSENTIAL_ANNUAL = '21600.00';      // (1200+180+420) * 12

    public const RF_DISCRETIONARY_ANNUAL = '720.00';    // (15+45) * 12

    public const RF_SALARY_ANNUAL = '30000.00';         // 2500 * 12

    /**
     * Rob's "Pay and Expenditures" workbook shape: a top income block (column B = yearly),
     * a DECOY deductions block whose header reuses "Expenditure Item / Deduction Amount /
     * % of Total Pay" but lacks "% of Take Home Pay", then the REAL expenditure block
     * (the only header carrying "% of Take Home Pay"), ending in Total + Remainder rows.
     */
    public static function payAndExpenditures(): Spreadsheet
    {
        return new Spreadsheet([
            // A non-scenario tab the profile must skip over.
            'Demo Mortgage Rates' => [['Max purchase price', 'Loan amount']],
            'Demo Golden Gate' => [
                ['', 'Yearly', 'Monthly', '', '', 'Yearly', 'Monthly'],
                ['Alex Pension DLA (Disability Living Allowance)', '6000', '500', 'Joint Account 123'],
                ['Alex Pension SP (State Pension)', '10400', '866', 'Demo Personal 456'],
                ['Blake Yearly Salary', '30000', '2500', '', 'Blake Pension', '12000', '1000'],
                ['Total Pay', '46400', '3866'],
                [],
                // DECOY deductions header: "% of Total Pay" but NOT "% of Take Home Pay".
                ['Expenditure Item', 'Deduction Amount', '% of Total Pay', '', 'Item', 'Cost'],
                ['P.A.Y.E', '250', '0.06', '', 'MPG (Miles per gallon)', '40'],
                ['N.I.', '200', '0.05'],
                ['Pension', '100', '0.02'],
                ['Child fund', '10'],
                ['Family Take home', '900', '0.20'],       // decoy: label contains "take home"
                ['Combined Take home Pay', '1500'],         // decoy: label contains "take home"
                [],
                // The REAL expenditure header — the only one with "% of Take Home Pay".
                ['Expenditure Item', 'Deduction Amount', '% of Total Pay', '% of Take Home Pay', 'Notes'],
                ['Mortgage', '1000', '0.26', '0.30', 'TMW'],
                ['Service Charge', '150', '0.03', '0.04'],
                ['Council Tax', '200', '0.05', '0.06', 'B&H BC'],
                ['Electric', '80', '0.02', '0.02', 'Octopus'],
                ['Internet', '30', '0.01', '0.01'],
                ['Food', '400', '0.10', '0.12'],
                ['Home Insurance', '90', '0.02', '0.03', 'Zurich'],
                ['Netflix', '15', '0.00', '0.00'],
                ['Car Insurance', '85', '0.02', '0.03'],
                // Trailing rows with an empty label but values in the % columns (must be skipped).
                ['', '', '0', '0'],
                ['', '', '0', '0'],
                ['Total', (string) self::PAYEXP_MONTHLY_TOTAL, '0.51', '0.61'],
                ['Remainder', '0'],
            ],
        ]);
    }

    /**
     * IWT Conscious Spending Plan "Example" shape: a NET WORTH section (balance-sheet rows,
     * two of which — Investments, Savings — carry bucket keywords and must be ignored), an
     * INCOME section, then the four buckets, EACH closed by a "… TOTAL" row that states the
     * bucket figure (and must not be added on top of the line items).
     */
    public static function consciousSpendingPlan(): Spreadsheet
    {
        return new Spreadsheet(['' => [
            ['Conscious Spending Plan'],
            [],
            ['NET WORTH', '$'],
            ['Assets (current value of car, home, property, business)', '500000'],
            ['Investments (include 401K, non retirement — all investments)', (string) self::CSP_NETWORTH_INVESTMENTS],
            ['Savings', (string) self::CSP_NETWORTH_SAVINGS],
            ['Debt (students loans, credit card debt, mortgage)', '150000'],
            ['TOTAL NET WORTH', '550000'],
            [],
            ['INCOME'],
            ['Gross monthly income (all income before taxes added up)', '8000'],
            ['Net monthly income (how much you take home after taxes)', '6000'],
            // Bucket: FIXED COSTS -> essential. Header carries a percentage (< £1, ignored).
            ['FIXED COSTS (50-60% of take home)', '0.55'],
            ['Rent / Mortgage', '2000', 'Monthly'],
            ['Utilities (gas, water, electric, internet, cable, etc.)', '300', 'Monthly'],
            ['Insurance (medical, auto, home / renters, etc.)', '200', 'Monthly'],
            ['Groceries', '500', 'Monthly'],
            ['Phone', '100', 'Monthly'],
            ['Subscriptions (Netflix, gym membership, meal services, Amazon, etc.)', '50', 'Monthly'],
            ['Miscellaneous (automatically adds 15% for things you forgot)', '150', 'Monthly'],
            ['FIXED COSTS TOTAL', (string) self::CSP_FIXED_MONTHLY_TOTAL],
            // Bucket: INVESTMENTS + SAVINGS -> contribution (not spending).
            ['INVESTMENTS (10% of take home)', '0.10'],
            ['Post-Tax Retirement Savings', '400', 'Monthly'],
            ['Stocks', '200', 'Monthly'],
            ['INVESTMENTS TOTAL', '600'],
            ['SAVINGS GOALS (5-10% of take home)', '0.07'],
            ['Vacations', '150', 'Monthly'],
            ['Long Term Emergency Fund', '250', 'Monthly'],
            ['SAVINGS TOTAL', '400'],
            // Bucket: GUILT-FREE -> discretionary. Only a TOTAL row, no line items.
            ['GUILT-FREE SPENDING (20-35% of take home)', '0.25'],
            ['GUILT-FREE SPENDING TOTAL (Dining out, movies, anything you want!)', (string) self::CSP_GUILT_MONTHLY_TOTAL],
        ]]);
    }

    /**
     * The CSP "Example" shape but with the FIXED COSTS TOTAL deliberately wrong: its line
     * items sum to 3300/mo while the TOTAL row claims 9999/mo. The importer trusts the stated
     * TOTAL, so the wrong figure reaches the form — and the reconciliation panel must surface
     * the disagreement loudly rather than silently picking one (CLAUDE.md: a mismatch is a
     * visible failure, not a silent one).
     */
    public static function consciousSpendingPlanWithInconsistentFixedTotal(): Spreadsheet
    {
        return new Spreadsheet(['' => [
            ['FIXED COSTS (50-60% of take home)', '0.55'],
            ['Rent / Mortgage', '2000', 'Monthly'],
            ['Utilities (gas, water, electric, internet, cable, etc.)', '300', 'Monthly'],
            ['Insurance (medical, auto, home / renters, etc.)', '200', 'Monthly'],
            ['Groceries', '500', 'Monthly'],
            ['Phone', '100', 'Monthly'],
            ['Subscriptions (Netflix, gym membership, etc.)', '50', 'Monthly'],
            ['Miscellaneous (automatically adds 15% for things you forgot)', '150', 'Monthly'],
            ['FIXED COSTS TOTAL', (string) self::CSP_FIXED_INCONSISTENT_TOTAL],  // != the 3300 line sum
            ['GUILT-FREE SPENDING (20-35% of take home)', '0.25'],
            ['GUILT-FREE SPENDING TOTAL (Dining out, movies, anything you want!)', (string) self::CSP_GUILT_MONTHLY_TOTAL],
        ]]);
    }

    /** The project's own CSV template: header, then section,label,monthly_amount rows. */
    public static function retireForecastCsv(): Spreadsheet
    {
        $csv = <<<'CSV'
        section,label,monthly_amount
        essential,Rent,1200.00
        essential,Council Tax,180.00
        essential,Food,420.00
        discretionary,Netflix,15.00
        discretionary,Gym,45.00
        salary,Gross salary,2500.00
        savings,Pension contribution,300.00
        CSV;

        return Spreadsheet::fromCsv($csv);
    }
}
