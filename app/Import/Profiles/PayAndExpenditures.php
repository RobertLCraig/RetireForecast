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

        // Each monthly outgoing becomes an essential 3-tier line (Phase C1), its label
        // preserved and the amount annualised — so the import populates line items, not one
        // lumped essential total. The annual sum still reconciles to the sheet's Total row.
        $expenseLines = [];
        foreach (array_slice($rows, $header + 1) as $cells) {
            $raw = trim($cells[0] ?? '');
            $label = strtolower($raw);
            $amount = trim($cells[1] ?? '');

            if ($raw === '') {
                continue;
            }
            if (str_contains($label, 'total') || str_contains($label, 'remainder')) {
                break; // the block ends at its total / remainder
            }
            if (! MoneyText::looksNumeric($amount)) {
                continue;
            }

            $expenseLines[] = [
                'label' => $raw,
                'amount' => MoneyText::fromPence(MoneyText::toPence($amount) * 12),
                'category' => 'essential',
                'savedAsAsset' => false,
            ];
        }

        if ($expenseLines === []) {
            throw new ImportException('Found the expenditure header but no monthly line items below it.');
        }

        return $this->result($tab, $expenseLines, $this->yearlySalary($rows), $this->incomeBlock($rows));
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
                if ($this->isExpenditureHeader($cells)) {
                    return [$name, $rows];
                }
            }
        }

        return ['', null];
    }

    /** @param list<list<string>> $rows */
    private function expenditureHeaderRow(array $rows): int
    {
        foreach ($rows as $i => $cells) {
            if ($this->isExpenditureHeader($cells)) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * The expenditure header row, identified ONLY by "% of Take Home Pay" in a cell.
     * That phrase is unique to it: the bare "take home" also appears in deduction row
     * labels ("Mum Take home", "Combined Take home Pay") just above, and "Expenditure
     * Item" / "Deduction Amount" are reused by the deductions header higher up — only the
     * real expenditure header carries the take-home-pay percentage column.
     *
     * @param  list<string>  $cells
     */
    private function isExpenditureHeader(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (str_contains(strtolower((string) $cell), '% of take home')) {
                return true;
            }
        }

        return false;
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

    /**
     * The income block above the deductions header: a State Pension row maps to a state
     * pension, DLA to tax-free income, and any other income (incl. a pension named in a
     * later column) to an income stream. Owner is Person 1 and no start age is set — both
     * are flagged for the user, since the sheet does not carry ages or split the people.
     *
     * @param  list<list<string>>  $rows
     * @return array{incomeStreams: list<array<string, mixed>>, pensions: list<array<string, mixed>>, filled: list<string>}
     */
    private function incomeBlock(array $rows): array
    {
        $end = $this->firstDeductionHeaderRow($rows);
        $incomeStreams = [];
        $pensions = [];
        $filled = [];

        foreach (array_slice($rows, 0, $end) as $cells) {
            $label = strtolower(trim($cells[0] ?? ''));
            $amount = trim($cells[1] ?? '');

            if ($label !== '' && MoneyText::looksNumeric($amount) && ! str_contains($label, 'total') && ! str_contains($label, 'salary')) {
                if (str_contains($label, 'state pension')) {
                    $weekly = MoneyText::fromPence((int) round(MoneyText::toPence($amount) / 52));
                    $pensions[] = $this->statePensionRow($weekly);
                    $filled[] = "State Pension (£{$weekly}/wk)";
                } else {
                    $taxable = ! str_contains($label, 'dla') && ! str_contains($label, 'disability');
                    $annual = MoneyText::fromPence(MoneyText::toPence($amount));
                    $incomeStreams[] = $this->incomeStreamRow('other', $annual, $taxable);
                    $filled[] = ($taxable ? 'Income' : 'Tax-free income')." (£{$annual}/yr)";
                }
            }

            // A pension named in a later column (e.g. "Blake Pension" with its amount beside it).
            for ($i = 2; $i + 1 < count($cells); $i++) {
                if (str_contains(strtolower((string) $cells[$i]), 'pension') && MoneyText::looksNumeric(trim((string) $cells[$i + 1]))) {
                    $annual = MoneyText::fromPence(MoneyText::toPence((string) $cells[$i + 1]));
                    $incomeStreams[] = $this->incomeStreamRow('annuity', $annual, true);
                    $filled[] = "Pension income (£{$annual}/yr)";
                    break;
                }
            }
        }

        return ['incomeStreams' => $incomeStreams, 'pensions' => $pensions, 'filled' => $filled];
    }

    /** @param list<list<string>> $rows */
    private function firstDeductionHeaderRow(array $rows): int
    {
        foreach ($rows as $i => $cells) {
            foreach ($cells as $cell) {
                if (str_contains(strtolower((string) $cell), 'deduction amount')) {
                    return $i;
                }
            }
        }

        return count($rows);
    }

    /** @return array<string, mixed> a builder income-stream row, owner Person 1, no start age. */
    private function incomeStreamRow(string $type, string $grossAnnual, bool $taxable): array
    {
        return [
            'ownerId' => 'p1', 'type' => $type, 'grossAnnual' => $grossAnnual,
            'taxable' => $taxable, 'inflationLinked' => true, 'startAge' => '', 'endAge' => '',
        ];
    }

    /** @return array<string, mixed> a builder state-pension row (full shape), owner Person 1. */
    private function statePensionRow(string $weeklyForecast): array
    {
        return [
            'ownerId' => 'p1', 'subtype' => 'state', 'currentValue' => '', 'ongoingContribution' => '',
            'employerContribution' => '', 'earliestAccessAge' => '57', 'pclsTakenToDate' => '',
            'growthAssumptionOverride' => '', 'withdrawals' => [], 'accruedAnnualPension' => '',
            'normalRetirementAge' => '65', 'revaluationBasis' => 'cpi', 'escalationInPayment' => 'cpi',
            'spousePensionFraction' => '', 'commutationLumpSum' => '', 'commutationFactor' => '',
            'weeklyForecast' => $weeklyForecast, 'qualifyingYears' => '', 'deferralWeeks' => '0',
        ];
    }

    /**
     * @param  list<array{label: string, amount: string, category: string, savedAsAsset: bool}>  $expenseLines
     * @param  array{incomeStreams: list<array<string, mixed>>, pensions: list<array<string, mixed>>, filled: list<string>}  $income
     */
    private function result(string $tab, array $expenseLines, ?string $salaryAnnual, array $income): ImportResult
    {
        // The essential total is the exact-pence sum of the lines — the same single source
        // the lines themselves carry, so the headline figure cannot drift from them.
        $essentialPence = array_sum(array_map(fn (array $l): int => MoneyText::toPence($l['amount']), $expenseLines));
        $essential = MoneyText::fromPence($essentialPence);
        $lines = count($expenseLines);

        $filled = ["Essential spending (£{$essential}/yr, from {$lines} monthly outgoing lines)"];
        if ($salaryAnnual !== null) {
            $filled[] = "Gross salary (£{$salaryAnnual}/yr)";
        }
        $filled = array_merge($filled, $income['filled']);

        $importedIncome = $income['incomeStreams'] !== [] || $income['pensions'] !== [];

        return new ImportResult(
            expense: ['essential' => $essential],
            expenseLines: $expenseLines,
            salaryAnnual: $salaryAnnual,
            incomeStreams: $income['incomeStreams'],
            pensions: $income['pensions'],
            filled: $filled,
            missing: array_values(array_filter([
                'A discretionary split — everything imported as essential for now',
                $importedIncome ? null : 'Pension and benefit income on the Pensions & income step',
                'Each person\'s date of birth and personal details',
                'Savings and investment balances, and the housing decision to compare',
            ])),
            notes: array_values(array_filter([
                "Imported from the '{$tab}' tab; monthly outgoings were multiplied to annual figures.",
                'All outgoings imported as essential — move any discretionary lines (subscriptions, etc.) to discretionary on the Spending step.',
                $salaryAnnual !== null ? 'The salary was read as a yearly figure.' : null,
                $importedIncome ? 'Income was imported onto Person 1 with no start age — set start ages, split across people, and check each type/tax flag on the Pensions & income step.' : null,
            ])),
        );
    }
}
