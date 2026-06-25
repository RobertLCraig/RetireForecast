<?php

declare(strict_types=1);

namespace App\Import;

/**
 * The outcome of reading a budget spreadsheet: the parts of the scenario-builder form
 * state a profile could fill, plus an honest account of what it filled and what the
 * user still has to enter by hand. Budget templates carry cashflow (spending, salary)
 * but not the balance-sheet or personal detail the forecast needs, so {@see $missing}
 * is never empty for them — the import pre-fills, it does not complete.
 */
final class ImportResult
{
    /**
     * @param  array<string, string>  $expense  partial builder expense state, e.g. ['essential' => '28160.00']
     * @param  string|null  $salaryAnnual  person-1 gross salary as a pounds string, if the sheet gave one
     * @param  list<string>  $filled  human labels of what was populated
     * @param  list<string>  $missing  human labels of what the user still needs to enter
     * @param  list<string>  $notes  caveats worth surfacing (e.g. how amounts were interpreted)
     */
    public function __construct(
        public readonly array $expense = [],
        public readonly ?string $salaryAnnual = null,
        public readonly array $filled = [],
        public readonly array $missing = [],
        public readonly array $notes = [],
    ) {}
}
