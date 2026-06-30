<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Finance\Mapping\Codec;
use DateTimeImmutable;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\DbPension;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\IncomeStream;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\PensionEscalationBasis;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Dto\WithdrawalInstruction;
use RetireForecast\FinanceEngine\Housing\SellingCostComponent;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Pension\WithdrawalKind;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;

/**
 * Turns the scenario builder's plain form state into the engine's input DTOs. This
 * is the third consumer of the one canonical shape (engine + storage + UI): the
 * builder collects strings, this assembles them into a {@see Household} and a
 * {@see HousingAction}, and the mappers serialise those for storage.
 *
 * Pounds-and-pence the user types are parsed to exact integer pence here (no float
 * in money), so a value entered, assembled, stored and re-read is lossless. Kept
 * separate from the Livewire component so it is unit-testable and reusable (e.g. the
 * demo preset).
 */
final class HouseholdAssembler
{
    /**
     * @param  array<string, mixed>  $state  the builder's validated form state
     * @return array{household: Household, housingAction: HousingAction}
     */
    public function assemble(array $state): array
    {
        return [
            'household' => $this->household($state),
            'housingAction' => $this->housingAction($state['housing'] ?? []),
        ];
    }

    public function household(array $state): Household
    {
        // A spend line switched off in the builder (included === false) is kept in the form-state
        // so it can be switched back on, but must contribute nothing to the forecast. Drop the
        // excluded lines once here so every downstream total (essential, discretionary, contingent
        // costs, saved self-investment) excludes them uniformly. Absent flag = included (back-compat).
        $state['expenseLines'] = array_values(array_filter(
            $state['expenseLines'] ?? [],
            static fn (array $line): bool => ($line['included'] ?? true) !== false,
        ));

        return new Household(
            name: (string) $state['householdName'],
            region: RegionProfile::from($state['region']),
            persons: array_map($this->person(...), $state['people'] ?? []),
            expenseProfile: $this->expenseProfile($state),
            pensions: array_map($this->pension(...), $state['pensions'] ?? []),
            accounts: $this->accounts($state),
            incomeStreams: array_map($this->incomeStream(...), $state['incomeStreams'] ?? []),
            primaryResidence: ($state['hasProperty'] ?? false) ? $this->property($state['property'] ?? []) : null,
        );
    }

    public function housingAction(array $h): HousingAction
    {
        return new HousingAction(
            salePrice: $this->moneyRequired($h['salePrice'] ?? null),
            buyPrice: $this->money($h['buyPrice'] ?? null),
            annualRent: $this->money($h['annualRent'] ?? null),
            rentInflationReal: $this->percent($h['rentInflationReal'] ?? null),
            movingCosts: $this->money($h['movingCosts'] ?? null),
            sellingCosts: $this->sellingCosts($h),
        );
    }

    /**
     * The selling-cost components, each entered on its own basis — a % of the sale price
     * (how agents quote) or a flat £ (how conveyancing quotes), the basis being the value's
     * type. A blank component contributes nothing and is dropped; all-blank yields null, so
     * the engine applies its own default rate (matching the old empty-rate behaviour).
     *
     * Back-compat: a scenario saved before the breakdown existed carries only the old single
     * `sellingCostRate`. It maps to one estate-agent component on that %, preserving the old
     * total exactly (the other components default to nothing); a blank old rate yields null,
     * so the engine default still applies. One home per figure — never both shapes at once.
     *
     * @param  array<string, mixed>  $h  the housing form-state
     * @return list<SellingCostComponent>|null
     */
    private function sellingCosts(array $h): ?array
    {
        if (isset($h['sellingCosts']) && is_array($h['sellingCosts'])) {
            $components = [];
            foreach ($h['sellingCosts'] as $line) {
                $value = (string) ($line['value'] ?? '');
                if (trim($value) === '') {
                    continue; // an empty line costs nothing
                }

                $components[] = new SellingCostComponent(
                    (string) ($line['label'] ?? 'Selling cost'),
                    ($line['basis'] ?? 'percent') === 'fixed'
                        ? Money::fromPence($this->toPence($value))
                        : Percent::fromPercent((float) $value),
                );
            }

            return $components === [] ? null : $components;
        }

        // Back-compat: the old single rate becomes one estate-agent component on that %.
        $rate = $this->percent($h['sellingCostRate'] ?? null);

        return $rate === null ? null : [new SellingCostComponent('Estate agent', $rate)];
    }

    private function person(array $p): Person
    {
        return new Person(
            id: (string) $p['id'],
            dob: $this->date($p['dob']),
            sex: Sex::from($p['sex']),
            employmentStatus: EmploymentStatus::from($p['employmentStatus']),
            grossSalary: $this->money($p['grossSalary'] ?? null),
            salaryGrowth: $this->percent($p['salaryGrowth'] ?? null),
            plannedRetirementAge: $this->intOrNull($p['plannedRetirementAge'] ?? null),
            niCategory: $this->stringOrNull($p['niCategory'] ?? null),
            name: $this->stringOrNull($p['name'] ?? null),
            longevity: $this->longevity($p),
        );
    }

    /**
     * The lifespan what-if for a person: "fixed_age" assumes death at a given age,
     * "offset_years" shifts the cohort-table peer death age by ± whole years. "peer" (or
     * an absent/blank field) leaves the cohort-table average untouched (null). This only
     * moves when a death occurs — never any tax or cashflow figure.
     *
     * @param  array<string, mixed>  $p
     */
    private function longevity(array $p): ?LongevityAdjustment
    {
        $value = $p['longevityValue'] ?? '';
        if ($value === '' || $value === null) {
            return null;
        }

        return match ($p['longevityMode'] ?? 'peer') {
            'fixed_age' => LongevityAdjustment::fixedAge((int) $value),
            'offset_years' => LongevityAdjustment::offsetYears((int) $value),
            default => null,
        };
    }

    /**
     * The household's spending. With Phase C1, the **line items are the source**: the
     * essential and discretionary annual totals are the *sum of the lines* (no stored
     * total to drift). Spending self-investment (courses, books — `savedAsAsset` false)
     * is consumption, so it folds into discretionary; saved self-investment is not
     * spend at all (it builds net worth — see {@see accounts()}). A scenario predating
     * line items (none present) falls back to the flat essential/discretionary totals.
     */
    private function expenseProfile(array $state): ExpenseProfile
    {
        $e = $state['expense'] ?? [];
        [$essential, $discretionary] = $this->essentialAndDiscretionary($state);

        // Contingent costs (option b): the portions of spend tied to a condition, summed from
        // the spend lines whose condition (explicit override, else auto-classified by label)
        // is housing- or employment-linked. They are a marked subset of essential/discretionary,
        // so the engine can stop charging them when the home is sold / the household retires.
        $lines = $state['expenseLines'] ?? [];
        $isSpend = fn (array $l): bool => ! (($l['category'] ?? '') === 'self_investment' && ($l['savedAsAsset'] ?? false));
        $propertyCosts = $this->sumLines($lines, fn (array $l): bool => $isSpend($l) && $this->lineCondition($l) === 'while_owning_home');
        $employmentCosts = $this->sumLines($lines, fn (array $l): bool => $isSpend($l) && $this->lineCondition($l) === 'while_working');

        return new ExpenseProfile(
            essentialAnnualSpend: $essential,
            discretionaryAnnualSpend: $discretionary,
            survivorSpendFactor: $this->percent($e['survivorFactor'] ?? null) ?? Percent::fromPercent(70),
            oneOffCosts: array_map(fn (array $c): array => [
                'atAge' => (int) $c['atAge'],
                'amount' => $this->moneyRequired($c['amount'] ?? null),
                'label' => (string) ($c['label'] ?? ''),
            ], $state['oneOffCosts'] ?? []),
            propertyCosts: $propertyCosts->isPositive() ? $propertyCosts : null,
            employmentCosts: $employmentCosts->isPositive() ? $employmentCosts : null,
        );
    }

    /**
     * The condition under which an expense line is charged — the user's explicit override if
     * set (option b's per-line override), else auto-classified by label: housing-linked labels
     * (mortgage, service charge, ground rent) are charged only *while owning* the current home;
     * commuting only *while working*; everything else *always*. Saved self-investment is not
     * spend, so callers exclude it before classifying.
     *
     * @param  array<string, mixed>  $line
     */
    private function lineCondition(array $line): string
    {
        $explicit = $line['condition'] ?? null;
        if (is_string($explicit) && in_array($explicit, ['always', 'while_owning_home', 'while_working'], true)) {
            return $explicit;
        }

        return self::autoCondition($line);
    }

    /**
     * The condition a line auto-classifies to from its label alone (ignoring any explicit
     * override): housing-linked labels (mortgage, service charge, ground rent, factor fee)
     * are charged only *while owning* the current home; commuting only *while working*;
     * everything else *always*. Public so the builder can show what "Auto" would infer.
     *
     * @param  array<string, mixed>  $line
     */
    public static function autoCondition(array $line): string
    {
        $label = strtolower((string) ($line['label'] ?? ''));
        foreach (['mortgage', 'service charge', 'ground rent', 'factor fee'] as $keyword) {
            if (str_contains($label, $keyword)) {
                return 'while_owning_home';
            }
        }
        foreach (['commute', 'commuting', 'season ticket'] as $keyword) {
            if (str_contains($label, $keyword)) {
                return 'while_working';
            }
        }

        return 'always';
    }

    /**
     * The essential floor and the discretionary spend on top, derived from the 3-tier
     * line items when present (essential = sum of essential lines; discretionary = sum
     * of discretionary lines + *spent* self-investment), else from the legacy flat
     * totals.
     *
     * @param  array<string, mixed>  $state
     * @return array{0: Money, 1: Money}
     */
    private function essentialAndDiscretionary(array $state): array
    {
        $lines = $state['expenseLines'] ?? [];
        if ($lines === []) {
            $e = $state['expense'] ?? [];

            return [
                $this->moneyRequired($e['essential'] ?? null),
                $this->money($e['discretionary'] ?? null) ?? Money::zero(),
            ];
        }

        $essential = $this->sumLines($lines, fn (array $l): bool => ($l['category'] ?? '') === 'essential');
        $discretionary = $this->sumLines($lines, fn (array $l): bool => ($l['category'] ?? '') === 'discretionary'
            || (($l['category'] ?? '') === 'self_investment' && ! ($l['savedAsAsset'] ?? false)));

        return [$essential, $discretionary];
    }

    /**
     * The household's accounts, plus — when there is *saved* self-investment (a
     * self-investment line flagged `savedAsAsset`) — a synthetic ISA whose ongoing
     * contributions are that saved amount. One home per pound: the saved line **is**
     * the contribution (funded from surplus, growing net worth), never also an account
     * balance, so it is counted once (gotcha O).
     *
     * @param  array<string, mixed>  $state
     * @return list<Account>
     */
    private function accounts(array $state): array
    {
        $accounts = array_map($this->account(...), $state['accounts'] ?? []);

        $saved = $this->sumLines(
            $state['expenseLines'] ?? [],
            fn (array $l): bool => ($l['category'] ?? '') === 'self_investment' && ($l['savedAsAsset'] ?? false),
        );

        if ($saved->isPositive()) {
            $accounts[] = new Account(
                ownerId: (string) ($state['people'][0]['id'] ?? 'p1'),
                type: AccountType::Isa,
                balance: Money::zero(),
                unrealisedGain: null,
                yield: null,
                ongoingContributions: $saved,
            );
        }

        return $accounts;
    }

    /**
     * Sum the (exact-pence) amounts of the expense lines matching $predicate.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  callable(array<string, mixed>): bool  $predicate
     */
    private function sumLines(array $lines, callable $predicate): Money
    {
        $pence = 0;
        foreach ($lines as $line) {
            if ($predicate($line)) {
                $pence += $this->toPence((string) ($line['amount'] ?? '0'));
            }
        }

        return Money::fromPence($pence);
    }

    private function pension(array $p): DcPension|DbPension|StatePensionEntitlement
    {
        return match ($p['subtype']) {
            'dc' => new DcPension(
                ownerId: (string) $p['ownerId'],
                currentValue: $this->moneyRequired($p['currentValue'] ?? null),
                ongoingContribution: $this->money($p['ongoingContribution'] ?? null) ?? Money::zero(),
                employerContribution: $this->money($p['employerContribution'] ?? null) ?? Money::zero(),
                earliestAccessAge: (int) $p['earliestAccessAge'],
                withdrawalPlan: array_map($this->withdrawal(...), $p['withdrawals'] ?? []),
                pclsTakenToDate: $this->money($p['pclsTakenToDate'] ?? null),
                growthAssumptionOverride: $this->percent($p['growthAssumptionOverride'] ?? null),
            ),
            'db' => new DbPension(
                ownerId: (string) $p['ownerId'],
                accruedAnnualPension: $this->moneyRequired($p['accruedAnnualPension'] ?? null),
                normalRetirementAge: (int) $p['normalRetirementAge'],
                revaluationBasis: PensionEscalationBasis::from($p['revaluationBasis'] ?? 'cpi'),
                escalationInPayment: PensionEscalationBasis::from($p['escalationInPayment'] ?? 'cpi'),
                spousePensionFraction: $this->percent($p['spousePensionFraction'] ?? null),
                commutationLumpSum: $this->money($p['commutationLumpSum'] ?? null),
                commutationFactor: $this->percent($p['commutationFactor'] ?? null),
            ),
            'state' => new StatePensionEntitlement(
                ownerId: (string) $p['ownerId'],
                weeklyForecast: $this->money($p['weeklyForecast'] ?? null),
                qualifyingYears: $this->intOrNull($p['qualifyingYears'] ?? null),
                deferralWeeks: (int) ($p['deferralWeeks'] ?? 0),
            ),
        };
    }

    private function withdrawal(array $w): WithdrawalInstruction
    {
        return new WithdrawalInstruction(
            kind: match ($w['kind']) {
                'pcls' => WithdrawalKind::Pcls,
                'ufpls' => WithdrawalKind::Ufpls,
                'drawdown' => WithdrawalKind::DrawdownIncome,
            },
            amount: $this->moneyRequired($w['amount'] ?? null),
            atAge: (int) $w['atAge'],
        );
    }

    private function account(array $a): Account
    {
        return new Account(
            ownerId: (string) $a['ownerId'],
            type: AccountType::from($a['type']),
            balance: $this->moneyRequired($a['balance'] ?? null),
            unrealisedGain: $this->money($a['unrealisedGain'] ?? null),
            yield: $this->percent($a['yield'] ?? null),
        );
    }

    private function incomeStream(array $s): IncomeStream
    {
        return new IncomeStream(
            ownerId: (string) $s['ownerId'],
            type: IncomeStreamType::from($s['type']),
            grossAnnual: $this->moneyRequired($s['grossAnnual'] ?? null),
            taxable: (bool) ($s['taxable'] ?? false),
            inflationLinked: (bool) ($s['inflationLinked'] ?? false),
            startAge: (int) $s['startAge'],
            endAge: $this->intOrNull($s['endAge'] ?? null),
        );
    }

    private function property(array $p): Property
    {
        return new Property(
            currentValue: $this->moneyRequired($p['currentValue'] ?? null),
            ownership: OwnershipType::from($p['ownership']),
            isPrimaryResidence: true,
            everLet: (bool) ($p['everLet'] ?? false),
            outstandingMortgage: $this->money($p['outstandingMortgage'] ?? null),
            runningCosts: $this->money($p['runningCosts'] ?? null),
            growthAssumptionOverride: $this->percent($p['growthAssumptionOverride'] ?? null),
            ownershipShare: $this->percent($p['ownershipShare'] ?? null),
        );
    }

    // --- primitive parsing (no float in money) -----------------------------------

    private function money(mixed $value): ?Money
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Money::fromPence($this->toPence((string) $value));
    }

    private function moneyRequired(mixed $value): Money
    {
        return $this->money($value) ?? Money::zero();
    }

    /** Parse a decimal pounds string to exact integer pence, no float involved. */
    private function toPence(string $value): int
    {
        $value = trim($value);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);

        $pence = (int) ($whole === '' ? '0' : $whole) * 100 + (int) ($fraction === '' ? '0' : $fraction);

        return $negative ? -$pence : $pence;
    }

    private function percent(mixed $value): ?Percent
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Percent::fromPercent((float) $value);
    }

    private function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }

    private function date(string $iso): DateTimeImmutable
    {
        return Codec::date($iso);
    }
}
