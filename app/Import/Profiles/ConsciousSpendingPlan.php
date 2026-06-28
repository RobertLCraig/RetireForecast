<?php

declare(strict_types=1);

namespace App\Import\Profiles;

use App\Import\ImportException;
use App\Import\ImportProfile;
use App\Import\ImportResult;
use App\Import\MoneyText;
use App\Import\ReconciliationLine;
use App\Import\Spreadsheet;

/**
 * Ramit Sethi / "I Will Teach You To Be Rich" Conscious Spending Plan, exported as CSV.
 *
 * The plan has four buckets — Fixed Costs, Investments, Savings, Guilt-Free Spending —
 * each line item carrying a spend amount and a Frequency (monthly / bi-weekly / …). We
 * map **Fixed Costs -> essential** and **Guilt-Free -> discretionary**, and treat
 * **Investments + Savings as contributions** (captured on the pension/savings steps, not
 * as spending). The plan is built on **net** take-home, so it cannot set a gross salary —
 * that is surfaced as still-to-do.
 *
 * The reader is header-driven and tolerant of column order: it tracks the current bucket
 * from section headers or a per-row bucket cell, finds the amount (preferring a currency
 * cell, skipping the % column) and normalises by the row's frequency to an annual figure.
 * It refuses a file with no recognisable buckets, and the imported totals are shown back
 * for review — verify them against your sheet, since CSP ships in several versions.
 */
final class ConsciousSpendingPlan implements ImportProfile
{
    /** Lower-case fragments that identify a bucket header (or per-row bucket), and where it maps. */
    private const BUCKETS = [
        'fixed' => 'essential',         // "Fixed Costs"
        'guilt' => 'discretionary',     // "Guilt-Free Spending"
        'investment' => 'contribution', // "Investments"
        'saving' => 'contribution',     // "Savings"
    ];

    public function key(): string
    {
        return 'iwt-csp';
    }

    public function label(): string
    {
        return 'IWT Conscious Spending Plan';
    }

    public function description(): string
    {
        return "Ramit Sethi's four-bucket plan (Fixed, Investments, Savings, Guilt-Free). Export the sheet as CSV.";
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function parse(Spreadsheet $sheet): ImportResult
    {
        $rows = $sheet->rows();
        if ($rows === []) {
            throw new ImportException('The file is empty.');
        }

        // Per bucket we track the line items separately from any "… TOTAL" row, because the
        // sheet states both. The TOTAL is authoritative when present (see the merge below).
        $lineSum = ['essential' => 0, 'discretionary' => 0, 'contribution' => 0];
        $totalSum = ['essential' => 0, 'discretionary' => 0, 'contribution' => 0];
        $seen = [];
        $currentBucket = null;
        $inNonBucketSection = false;

        foreach ($rows as $cells) {
            if ($cells === []) {
                continue;
            }

            $rowBucket = $this->bucketIn($cells);
            $amount = $this->amountIn($cells);

            if ($amount === null) {
                // A header row. A bucket header opens a spending section; a NET WORTH / INCOME
                // header opens a non-bucket section whose rows must be ignored — even the
                // "Investments" / "Savings" balance-sheet rows that happen to carry a bucket
                // keyword (they are assets, not spending or contributions).
                if ($rowBucket !== null) {
                    $currentBucket = $rowBucket;
                    $inNonBucketSection = false;
                } elseif ($this->isNonBucketSectionHeader($cells)) {
                    $inNonBucketSection = true;
                }

                continue;
            }

            if ($inNonBucketSection) {
                continue; // a balance-sheet / income figure — never a spending or contribution line
            }

            $bucket = $rowBucket ?? $currentBucket;
            if ($bucket === null) {
                continue; // an amount before any bucket (e.g. a stray net-income row) — skip
            }

            $annual = $amount * $this->frequencyFactor($cells);
            if ($this->isBucketTotalRow($cells)) {
                $totalSum[$bucket] += $annual;
            } else {
                $lineSum[$bucket] += $annual;
            }
            $seen[$bucket] = true;
        }

        if ($seen === []) {
            throw new ImportException('No Conscious Spending Plan buckets (Fixed Costs / Guilt-Free / Investments / Savings) were found. Export the plan sheet as CSV.');
        }

        // A bucket that states its own "… TOTAL" is authoritative: use it and do NOT also add
        // the line items (that double-counts) or the NET WORTH rows that share its keyword
        // ("Investments"/"Savings" assets). Only when no total is given do we sum the line items.
        $annualPence = [];
        foreach (array_keys($lineSum) as $bucket) {
            $annualPence[$bucket] = $totalSum[$bucket] > 0 ? $totalSum[$bucket] : $lineSum[$bucket];
        }

        return $this->result($annualPence, $seen, $lineSum, $totalSum);
    }

    /** The destination bucket named in this row, if any. */
    private function bucketIn(array $cells): ?string
    {
        foreach ($cells as $cell) {
            $lower = strtolower(trim((string) $cell));
            foreach (self::BUCKETS as $fragment => $destination) {
                if ($lower !== '' && str_contains($lower, $fragment)) {
                    return $destination;
                }
            }
        }

        return null;
    }

    /** Whether this row is a bucket subtotal ("FIXED COSTS TOTAL", "SAVINGS TOTAL", …) rather than a line item. */
    private function isBucketTotalRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (str_contains(strtolower((string) $cell), 'total')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this header opens a NET WORTH or INCOME section — neither holds spending, so its
     * rows are skipped (the balance-sheet "Investments" / "Savings" assets carry a bucket
     * keyword but must not be summed as contributions). A real bucket header is checked first
     * by the caller, so a bucket that happened to mention "income" still wins.
     */
    private function isNonBucketSectionHeader(array $cells): bool
    {
        foreach ($cells as $cell) {
            $lower = strtolower(trim((string) $cell));
            if ($lower !== '' && (str_contains($lower, 'net worth') || str_contains($lower, 'income'))) {
                return true;
            }
        }

        return false;
    }

    /** The spend amount in this row, in pence — a currency cell wins; else the largest >= £1 number. Percentages are skipped. */
    private function amountIn(array $cells): ?int
    {
        $fallback = null;

        foreach ($cells as $cell) {
            $cell = trim((string) $cell);
            if ($cell === '' || str_contains($cell, '%') || ! MoneyText::looksNumeric($cell)) {
                continue;
            }

            $pence = MoneyText::toPence($cell);

            if (preg_match('/[£$€]/', $cell) === 1) {
                return $pence; // an explicit currency amount is the strongest signal
            }
            if ($pence >= 100 && ($fallback === null || $pence > $fallback)) {
                $fallback = $pence; // ignore sub-£1 values (percentages written as decimals)
            }
        }

        return $fallback;
    }

    /** Annual multiplier implied by any frequency word in the row (default monthly). */
    private function frequencyFactor(array $cells): int
    {
        $text = strtolower(implode(' ', array_map('strval', $cells)));

        return match (true) {
            (bool) preg_match('/bi.?weekly|fortnight/', $text) => 26,
            (bool) preg_match('/semi.?month/', $text) => 24,
            (bool) preg_match('/weekly|per week/', $text) => 52,
            (bool) preg_match('/quarter/', $text) => 4,
            (bool) preg_match('/annual|yearly|per year|\/\s*year|per annum/', $text) => 1,
            default => 12, // monthly
        };
    }

    /**
     * @param  array<string, int>  $annualPence  the figure used per bucket (TOTAL if stated, else line sum)
     * @param  array<string, bool>  $seen
     * @param  array<string, int>  $lineSum  annual pence summed from the bucket's line items
     * @param  array<string, int>  $totalSum  annual pence from the bucket's own "… TOTAL" rows
     */
    private function result(array $annualPence, array $seen, array $lineSum, array $totalSum): ImportResult
    {
        $expense = [];
        $expenseLines = [];
        $filled = [];

        // One 3-tier line per bucket (Phase C1), carrying the bucket's authoritative annual
        // figure. We deliberately do NOT split the bucket back into its line items: the
        // sheet's own "… TOTAL" is the trusted figure, and re-expanding it would risk the
        // double-count the reconciliation guard exists to prevent. The line sum therefore
        // equals the bucket total exactly.
        if (isset($seen['essential'])) {
            $expense['essential'] = MoneyText::fromPence($annualPence['essential']);
            $expenseLines[] = ['label' => 'Fixed costs', 'amount' => $expense['essential'], 'category' => 'essential', 'savedAsAsset' => false];
            $filled[] = 'Essential spending from Fixed Costs (£'.$expense['essential'].'/yr)';
        }
        if (isset($seen['discretionary'])) {
            $expense['discretionary'] = MoneyText::fromPence($annualPence['discretionary']);
            $expenseLines[] = ['label' => 'Guilt-free spending', 'amount' => $expense['discretionary'], 'category' => 'discretionary', 'savedAsAsset' => false];
            $filled[] = 'Discretionary spending from Guilt-Free (£'.$expense['discretionary'].'/yr)';
        }

        $notes = ['Amounts were normalised by their Frequency to annual figures.'];
        if (isset($seen['contribution'])) {
            $notes[] = 'Investments + Savings buckets (£'.MoneyText::fromPence($annualPence['contribution']).'/yr) are contributions, not spending — add them on the Pensions & income / Your net worth steps.';
        }
        $notes[] = 'The plan is net-of-tax, so it cannot set a gross salary. Verify the imported totals below.';

        return new ImportResult(
            expense: $expense,
            expenseLines: $expenseLines,
            salaryAnnual: null, // CSP is built on net take-home; gross must be entered by hand
            filled: $filled,
            missing: [
                'Gross salary (the plan only has net take-home)',
                'Each person\'s date of birth and personal details',
                'Pensions (defined contribution, defined benefit, State Pension)',
                'Savings and investment balances',
                'The current home and the housing decision to compare',
            ],
            notes: $notes,
            reconciliation: $this->reconciliation($seen, $lineSum, $totalSum),
        );
    }

    /**
     * One cross-check per bucket. The figure that went into the form is the bucket's own
     * stated "… TOTAL" when present, otherwise the sum of its line items; the independent
     * figure for the panel is the *other* one (the line-item sum) when a TOTAL was used and
     * line items also exist. They must agree — a disagreement means the sheet's total and its
     * own line items diverge, which the panel surfaces loudly rather than silently picking one.
     *
     * @param  array<string, bool>  $seen
     * @param  array<string, int>  $lineSum
     * @param  array<string, int>  $totalSum
     * @return list<ReconciliationLine>
     */
    private function reconciliation(array $seen, array $lineSum, array $totalSum): array
    {
        $labels = [
            'essential' => 'Essential spending (Fixed Costs)',
            'discretionary' => 'Discretionary spending (Guilt-Free)',
            'contribution' => 'Contributions (Investments + Savings)',
        ];

        $lines = [];
        foreach ($labels as $bucket => $label) {
            if (! isset($seen[$bucket])) {
                continue;
            }

            $usedTotal = $totalSum[$bucket] > 0;
            $imported = MoneyText::fromPence($usedTotal ? $totalSum[$bucket] : $lineSum[$bucket]);

            // Cross-check only when both a stated total and line items exist; otherwise there
            // is just the one figure, surfaced for the user to eyeball (stated = null).
            $hasBoth = $usedTotal && $lineSum[$bucket] > 0;
            $stated = $hasBoth ? MoneyText::fromPence($lineSum[$bucket]) : null;
            $detail = $usedTotal
                ? "from the sheet's own bucket total"
                : 'summed from the line items';

            $lines[] = new ReconciliationLine($label, $imported, $stated, $detail);
        }

        return $lines;
    }
}
