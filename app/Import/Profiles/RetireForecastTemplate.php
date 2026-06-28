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
 * The project's own CSV template — the one profile available without a third-party
 * sample. It is the shape the IWT / Nischa readers will normalise to once calibrated.
 *
 * Format: a header row then `section,label,monthly_amount` rows, where section is one
 * of `essential`, `discretionary` or `salary` (others are ignored but counted as
 * skipped). Monthly amounts are summed in exact pence and multiplied to annual figures.
 * It populates spending and the main salary only; the rest of the household is the
 * wizard's job, and is reported back as still-to-do.
 */
final class RetireForecastTemplate implements ImportProfile
{
    private const SECTIONS = ['essential', 'discretionary', 'salary'];

    public function key(): string
    {
        return 'retireforecast';
    }

    public function label(): string
    {
        return 'RetireForecast template';
    }

    public function description(): string
    {
        return 'A simple CSV: section,label,monthly_amount rows for essential, discretionary and salary.';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function parse(Spreadsheet $sheet): ImportResult
    {
        $rows = $sheet->rows();
        if (count($rows) < 2) {
            throw new ImportException('The file has no data rows below the header.');
        }

        $totals = ['essential' => 0, 'discretionary' => 0, 'salary' => 0];
        $seen = [];
        // Per-line 3-tier items (Phase C1): each essential/discretionary row becomes a line,
        // its label preserved, so the import populates line items, not one lumped total.
        $lines = [];

        foreach (array_slice($rows, 1) as $cols) {
            $section = strtolower(trim($cols[0] ?? ''));
            $amount = trim($cols[2] ?? '');

            if (! in_array($section, self::SECTIONS, true) || $amount === '') {
                continue;
            }
            if (! MoneyText::looksNumeric($amount)) {
                throw new ImportException(sprintf('Row "%s" has an amount that is not a number.', implode(',', $cols)));
            }

            $monthlyPence = MoneyText::toPence($amount);
            $totals[$section] += $monthlyPence;
            $seen[$section] = true;

            if ($section === 'essential' || $section === 'discretionary') {
                $label = trim($cols[1] ?? '');
                $lines[] = [
                    'label' => $label === '' ? ucfirst($section) : $label,
                    'amount' => MoneyText::fromPence($monthlyPence * 12),
                    'category' => $section,
                    'savedAsAsset' => false,
                ];
            }
        }

        if ($seen === []) {
            throw new ImportException('No essential, discretionary or salary rows were found — this does not look like a RetireForecast template.');
        }

        return $this->result($totals, $seen, $lines);
    }

    /**
     * @param  array<string, int>  $monthlyPence
     * @param  array<string, bool>  $seen
     * @param  list<array{label: string, amount: string, category: string, savedAsAsset: bool}>  $lines
     */
    private function result(array $monthlyPence, array $seen, array $lines): ImportResult
    {
        $expense = [];
        $filled = [];
        $reconciliation = [];

        if (isset($seen['essential'])) {
            $annual = $monthlyPence['essential'] * 12;
            $expense['essential'] = MoneyText::fromPence($annual);
            $filled[] = 'Essential spending (£'.MoneyText::fromPence($annual).'/yr)';
            $reconciliation[] = $this->reconciliationLine('Essential spending', $expense['essential'], $lines, 'essential');
        }
        if (isset($seen['discretionary'])) {
            $annual = $monthlyPence['discretionary'] * 12;
            $expense['discretionary'] = MoneyText::fromPence($annual);
            $filled[] = 'Discretionary spending (£'.MoneyText::fromPence($annual).'/yr)';
            $reconciliation[] = $this->reconciliationLine('Discretionary spending', $expense['discretionary'], $lines, 'discretionary');
        }

        $salaryAnnual = null;
        if (isset($seen['salary'])) {
            $salaryAnnual = MoneyText::fromPence($monthlyPence['salary'] * 12);
            $filled[] = 'Salary for the first person (£'.$salaryAnnual.'/yr)';
        }

        return new ImportResult(
            expense: $expense,
            expenseLines: $lines,
            salaryAnnual: $salaryAnnual,
            filled: $filled,
            missing: [
                'Each person\'s date of birth and personal details',
                'Pensions (defined contribution, defined benefit, State Pension)',
                'Savings and investment balances',
                'The current home and the housing decision to compare',
            ],
            notes: ['Amounts were read as monthly and multiplied to annual figures.'],
            reconciliation: $reconciliation,
        );
    }

    /**
     * A reconciliation row for one category. This CSV layout states no independent total of
     * its own, so {@see ReconciliationLine::$stated} is null: the aggregated figure is
     * surfaced for the user to eyeball against their spreadsheet rather than auto-checked.
     *
     * @param  list<array{label: string, amount: string, category: string, savedAsAsset: bool}>  $lines
     */
    private function reconciliationLine(string $label, string $imported, array $lines, string $category): ReconciliationLine
    {
        $count = count(array_filter($lines, fn (array $l): bool => $l['category'] === $category));

        return new ReconciliationLine(
            label: $label,
            imported: $imported,
            stated: null,
            detail: "summed from {$count} monthly rows, each ×12",
        );
    }
}
