<?php

declare(strict_types=1);

namespace App\Import\Profiles;

use App\Import\ImportException;
use App\Import\ImportProfile;
use App\Import\ImportResult;
use App\Import\MoneyText;
use App\Import\Spreadsheet;

/**
 * Rob's personal "Pay and Expenditures" workbook. Each scenario lives on its own tab
 * (e.g. "Demo Flat A" = buy, "Demo Rental B" = rent), with a monthly expenditure
 * list under a header row containing "% of Take Home Pay" (label in column A, monthly
 * amount in column B), and a top income block where column B is the yearly figure.
 *
 * Scoped (by Rob's choice) to the expenditure list + the yearly salary: it sums the
 * outgoings into the essential annual spend and reads the salary as gross. Everything
 * imports as essential — there is no per-line essential/discretionary flag in the
 * sheet — and pensions/benefits stay to-do, reported honestly. It picks the first tab
 * that has an expenditure block and names it back.
 */
final class PayAndExpenditures implements ImportProfile
{
    public function key(): string
    {
        return 'pay-and-expenditures';
    }

    public function label(): string
    {
        return 'Pay & Expenditures (personal workbook)';
    }

    public function description(): string
    {
        return 'Reads the monthly expenditure list and yearly salary from an Demo-style scenario tab.';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function parse(Spreadsheet $sheet): ImportResult
    {
        [$tab, $rows] = $this->scenarioTab($sheet);
        if ($rows === null) {
            throw new ImportException('No expenditure tab found — this profile expects a sheet with a "% of Take Home Pay" expenditure list (an Demo-style tab).');
        }

        $header = $this->expenditureHeaderRow($rows);

        $monthlyPence = 0;
        $lines = 0;
        foreach (array_slice($rows, $header + 1) as $cells) {
            $label = strtolower(trim($cells[0] ?? ''));
            $amount = trim($cells[1] ?? '');

            if ($label === '') {
                continue;
            }
            if (str_contains($label, 'total') || str_contains($label, 'remainder')) {
                break; // the block ends at its total / remainder
            }
            if (! MoneyText::looksNumeric($amount)) {
                continue;
            }

            $monthlyPence += MoneyText::toPence($amount);
            $lines++;
        }

        if ($lines === 0) {
            throw new ImportException('Found the expenditure header but no monthly line items below it.');
        }

        return $this->result($tab, $monthlyPence * 12, $lines, $this->yearlySalary($rows));
    }

    /**
     * The first tab that has an expenditure block, with its rows.
     *
     * @return array{0: string, 1: list<list<string>>|null}
     */
    private function scenarioTab(Spreadsheet $sheet): array
    {
        foreach ($sheet->sheetNames() as $name) {
            $rows = $sheet->rows($name);
            foreach ($rows as $cells) {
                foreach ($cells as $cell) {
                    if (str_contains(strtolower($cell), 'take home')) {
                        return [$name, $rows];
                    }
                }
            }
        }

        return ['', null];
    }

    /** @param list<list<string>> $rows */
    private function expenditureHeaderRow(array $rows): int
    {
        foreach ($rows as $i => $cells) {
            foreach ($cells as $cell) {
                if (str_contains(strtolower($cell), 'take home')) {
                    return $i;
                }
            }
        }

        return 0;
    }

    /**
     * The yearly salary from the income block (column B is the yearly figure there).
     *
     * @param  list<list<string>>  $rows
     */
    private function yearlySalary(array $rows): ?string
    {
        foreach ($rows as $cells) {
            $label = strtolower(trim($cells[0] ?? ''));
            $amount = trim($cells[1] ?? '');
            if (str_contains($label, 'salary') && MoneyText::looksNumeric($amount)) {
                return MoneyText::fromPence(MoneyText::toPence($amount));
            }
        }

        return null;
    }

    private function result(string $tab, int $essentialAnnualPence, int $lines, ?string $salaryAnnual): ImportResult
    {
        $essential = MoneyText::fromPence($essentialAnnualPence);

        $filled = ["Essential spending (£{$essential}/yr, from {$lines} monthly outgoing lines)"];
        if ($salaryAnnual !== null) {
            $filled[] = "Gross salary (£{$salaryAnnual}/yr)";
        }

        return new ImportResult(
            expense: ['essential' => $essential],
            salaryAnnual: $salaryAnnual,
            filled: $filled,
            missing: [
                'A discretionary split — everything imported as essential for now',
                'Pension and benefit income (DLA, State Pension, partner pension) on the Pensions & income step',
                'Each person\'s date of birth and personal details',
                'Savings and investment balances, and the housing decision to compare',
            ],
            notes: array_filter([
                "Imported from the '{$tab}' tab; monthly outgoings were multiplied to annual figures.",
                'All outgoings imported as essential — move any discretionary lines (subscriptions, etc.) to discretionary on the Spending step.',
                $salaryAnnual !== null ? 'The salary was read as a yearly figure.' : null,
            ]),
        );
    }
}
