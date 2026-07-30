<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Finance\Mapping\AssumptionSetMapper;
use App\Forecast\ResultPresenter;
use App\Forecast\ScenarioForecaster;
use App\Import\MoneyText;
use App\Models\AssumptionSet;
use App\Models\Scenario;
use Illuminate\Console\Command;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use Throwable;

/**
 * Audits every stored scenario for correctness AND for correct disclosure — the checks a test suite
 * cannot make, because they run against the user's real saved scenarios rather than fixtures.
 *
 * It exists because two real defects reached the screen: a delta-child family silently changed
 * meaning when its base moved (four scenarios threw, three changed quietly), and a repayment
 * mortgage's zeroed spend line read as "the mortgage is not being charged". Both were found by eye.
 * This makes that sweep repeatable.
 *
 * The standing rule it enforces: **no figure the model uses may be invisible or wrong on screen** —
 * and its sibling, that a figure the engine ships must actually REACH the forecast rather than
 * sitting in code while the stored assumption set the app reads carries no such key.
 * Exits non-zero when anything is found, so it can gate a release.
 */
final class AuditScenarios extends Command
{
    protected $signature = 'scenarios:audit {--user= : only audit this user id}';

    protected $description = 'Check every stored scenario for correctness and correct display';

    public function handle(ScenarioForecaster $forecaster): int
    {
        $query = Scenario::query()->orderBy('id');
        if ($this->option('user') !== null) {
            $query->where('user_id', (int) $this->option('user'));
        }
        $scenarios = $query->get();

        if ($scenarios->isEmpty()) {
            $this->warn('No scenarios to audit.');

            return self::SUCCESS;
        }

        $problems = $this->staleAssumptionSets();
        $rows = [];

        foreach ($scenarios as $scenario) {
            $id = $scenario->id;
            $this->output->write("  auditing #{$id} {$scenario->name} ... ");

            try {
                $found = $this->auditOne($scenario, $forecaster, $rows);
            } catch (Throwable $e) {
                $problems[] = "#{$id} THREW: ".$e->getMessage();
                $this->output->writeln('<error>threw</error>');

                continue;
            }

            $problems = [...$problems, ...$found];
            $this->output->writeln($found === [] ? '<info>ok</info>' : '<comment>'.count($found).' problem(s)</comment>');
        }

        $this->newLine();
        $this->table(['id', 'scenario', 'variant', 'runs short', 'to spend/mo', 'wealth left'], $rows);
        $this->newLine();

        if ($problems === []) {
            $this->info('Audit clean: every scenario projects, and every figure it uses is shown.');

            return self::SUCCESS;
        }

        $this->error(count($problems).' problem(s) found:');
        foreach ($problems as $problem) {
            $this->line("  - {$problem}");
        }

        return self::FAILURE;
    }

    /**
     * A figure the engine ships is useless if it never reached the row the app actually runs
     * against. The scenarios read their assumptions from the `assumption_sets` TABLE, seeded once
     * from {@see AssumptionSetLibrary}; a figure added to the library afterwards stays absent from
     * the stored payload, where the mapper's (correct) back-compat hydration reads it as null. That
     * is right for a frozen RUN snapshot — an old result must reproduce — but wrong for the live
     * set, where it silently means "held at the pre-feature behaviour".
     *
     * It had already happened six times over: stochastic house growth, its equity correlation,
     * stochastic salary growth, its correlation, the above-CPI care escalation and the ongoing
     * investment charge were all shipped, tested and documented, yet none had ever reached a single
     * stored scenario. Only a MISSING key is a defect; a differing value is a legitimate admin edit.
     *
     * @return list<string>
     */
    private function staleAssumptionSets(): array
    {
        $problems = [];

        $shipped = [];
        foreach (AssumptionSetLibrary::all() as $dto) {
            $shipped[$dto->name] = array_keys(AssumptionSetMapper::payload($dto));
        }

        foreach (AssumptionSet::all() as $stored) {
            $expected = $shipped[$stored->name] ?? null;
            if ($expected === null) {
                continue; // an admin-created set is not the library's to police
            }
            $missing = array_values(array_diff($expected, array_keys($stored->payload)));
            if ($missing !== []) {
                $problems[] = "assumption set '{$stored->name}' is missing shipped figure(s) ".implode(', ', $missing)
                    .' — those figures are NOT reaching any forecast; run: php artisan db:seed --class=Database\\Seeders\\AssumptionSetSeeder';
            }
        }

        return $problems;
    }

    /**
     * @param  list<array<int, string>>  $rows
     * @return list<string>
     */
    private function auditOne(Scenario $scenario, ScenarioForecaster $forecaster, array &$rows): array
    {
        $problems = [];
        $id = $scenario->id;
        $state = $scenario->effectiveBuilderState();
        $variant = $state['variant'] ?? 'stay_put';
        $household = $scenario->toHousehold();
        $action = $scenario->toHousingAction();

        // A sell/rent plan MUST be read from its own variant; deterministic() always projects the
        // stay-put path, which silently reports the wrong plan.
        $forecast = $forecaster->deterministicVariants($scenario)[$variant];

        // 1. The listing's variant label must match what is actually modelled.
        if ($scenario->variant->value !== $variant) {
            $problems[] = "#{$id} is labelled '{$scenario->variant->value}' but models '{$variant}'";
        }

        // 2. Overrides pointing at keys the base no longer has do nothing, silently.
        $orphans = $scenario->orphanedOverrides();
        if ($orphans !== []) {
            // orphanedOverrides() returns a LIST of keys, not a keyed map — array_keys would print
            // useless indices instead of naming the broken overrides.
            $problems[] = "#{$id} has overrides that no longer apply: ".implode(', ', $orphans);
        }

        // 3. The mortgage the reader SEES must be the mortgage that is CHARGED. A mortgaged
        //    stay-put plan showing £0, with no roll-up to explain it, is the defect found in review.
        $home = $household->primaryResidence;
        $balance = $home?->outstandingMortgage?->pence ?? 0;
        $rollsUp = $home?->mortgageRollUpRate !== null;
        $amortises = $home?->repaymentTerms !== null;
        [$shown, $computed] = $this->mortgageLineShown($state, $household);

        if ($variant === 'stay_put' && $balance > 0 && $shown === 0 && ! $rollsUp) {
            $problems[] = "#{$id} shows £0 for the mortgage on a £".number_format($balance / 100)
                .' balance, with no roll-up to explain it — the reader cannot see what is being charged';
        }
        if ($amortises && ! $computed) {
            $problems[] = "#{$id} amortises a mortgage but its spend line is not marked as computed from the terms";
        }

        // 3b. LABELS must be right too, not just figures. A tier heading was once overwritten with
        //     its own last spend line ("Essential" showing as "Commute Fuel"), which no amount check
        //     could see. Compare each heading against the constant that OWNS the name — checking
        //     "is the tier named after one of its lines?" instead would fire on a household that
        //     happens to have a line called "Discretionary", which is entirely legitimate.
        foreach (ResultPresenter::expenseBreakdown($state, $household)['tiers'] as $tier) {
            $canonical = ResultPresenter::EXPENSE_TIERS[$tier['key']] ?? null;
            if ($canonical !== null && $tier['label'] !== $canonical) {
                $problems[] = "#{$id} spending tier '{$tier['key']}' is titled '{$tier['label']}', not '{$canonical}'";
            }
        }

        // 4. The monthly figures must reconcile every year (a total that drifts from its parts).
        foreach (ResultPresenter::ladder($forecast)['rows'] as $row) {
            $allowance = MoneyText::toPence($row['monthlyAllowance']);
            $parts = MoneyText::toPence($row['monthlyEssential']) + MoneyText::toPence($row['monthlyFree']);
            if ($allowance !== $parts) {
                $problems[] = "#{$id} {$row['year']}: the monthly parts do not sum to the allowance";
                break;
            }
        }

        // 5. A home modelled as LOSING value must actually lose it, and must say so.
        $growth = $action->buyGrowthOverride;
        if ($variant === 'buy_outright' && $growth !== null && $growth->basisPoints < 0) {
            $values = array_map(static fn ($y) => $y->propertyWealth->pence, $forecast->years);
            if (count($values) > 3 && $values[3] >= $values[0]) {
                $problems[] = "#{$id} is set to depreciate but its home is not losing value";
            }
            if ($this->notesOfKind($household, $forecast, $action, 'home_depreciates') === []) {
                $problems[] = "#{$id} depreciates without telling the reader";
            }
        }

        // 6. Money must never be conjured: an unfunded purchase has to be CHARGED in year 0. It need
        //    not surface as unmet spend — that year's income and savings may cover it.
        $unfunded = null;
        if ($variant === 'buy_outright') {
            $unfunded = $forecaster->housingComparison($scenario)->buyOutcome($household, $action)->unfundedGap;
            if ($unfunded->isPositive()) {
                $baseline = $household->expenseProfile->targetAnnualSpend()->pence;
                if ($forecast->years[0]->spendTarget->pence < $baseline + intdiv($unfunded->pence, 2)) {
                    $problems[] = "#{$id} has a £".number_format($unfunded->pence / 100)
                        .' unfunded purchase that is not charged — the home would arrive free';
                }
            }
        }

        // 7. Every figure the engine supplied for itself must be disclosed (the standing rule). If
        //    assumedFigures() finds defaults in play, they must reach the reader as notes.
        //    Both sides read the action THIS variant actually acts on: a base carries a buy price
        //    so Compare can run every variant, and a bought home's defaults reach only the plan
        //    that buys — so auditing the raw action would demand a disclosure the reader must not
        //    be shown, and pass a scenario that omitted a disclosure it should.
        $applicable = ResultPresenter::housingActionFor($action, $variant);
        $assumed = ResultPresenter::assumedFigures($household, $applicable);
        $disclosed = $this->notesOfKind($household, $forecast, $applicable, 'assumed_figure');
        if (count($assumed) !== count($disclosed)) {
            $problems[] = "#{$id} uses ".count($assumed).' assumed figure(s) but shows '.count($disclosed);
        }

        $rows[] = [
            (string) $id,
            mb_substr($scenario->name, 0, 40),
            $variant,
            (string) ($forecast->depletionCalendarYear ?? 'never'),
            ResultPresenter::spendableSummary($forecast)['now']['monthlyAllowance'] ?? '—',
            $forecast->terminalTotalWealth->format(),
        ];

        return $problems;
    }

    /**
     * The mortgage amount the budget panel shows, and whether it is flagged as computed from the
     * mortgage terms rather than typed in.
     *
     * @param  array<string, mixed>  $state
     * @return array{0: ?int, 1: bool}
     */
    private function mortgageLineShown(array $state, $household): array
    {
        foreach (ResultPresenter::expenseBreakdown($state, $household)['tiers'] as $tier) {
            foreach ($tier['lines'] as $line) {
                if (str_contains(mb_strtolower($line['label']), 'mortgage')) {
                    return [MoneyText::toPence($line['amount']), (bool) ($line['computed'] ?? false)];
                }
            }
        }

        return [null, false];
    }

    /** @return list<array{kind: string, text: string}> */
    private function notesOfKind($household, $forecast, $action, string $kind): array
    {
        return array_values(array_filter(
            ResultPresenter::inputNotes($household, $forecast, $action),
            static fn (array $note): bool => $note['kind'] === $kind,
        ));
    }
}
