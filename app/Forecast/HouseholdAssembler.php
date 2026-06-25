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
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\PensionEscalationBasis;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Dto\WithdrawalInstruction;
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
        return new Household(
            name: (string) $state['householdName'],
            region: RegionProfile::from($state['region']),
            persons: array_map($this->person(...), $state['people'] ?? []),
            expenseProfile: $this->expenseProfile($state['expense'] ?? [], $state['oneOffCosts'] ?? []),
            pensions: array_map($this->pension(...), $state['pensions'] ?? []),
            accounts: array_map($this->account(...), $state['accounts'] ?? []),
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
            sellingCostRate: $this->percent($h['sellingCostRate'] ?? null),
        );
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
        );
    }

    private function expenseProfile(array $e, array $oneOffs): ExpenseProfile
    {
        return new ExpenseProfile(
            essentialAnnualSpend: $this->moneyRequired($e['essential'] ?? null),
            discretionaryAnnualSpend: $this->money($e['discretionary'] ?? null) ?? Money::zero(),
            survivorSpendFactor: $this->percent($e['survivorFactor'] ?? null) ?? Percent::fromPercent(70),
            oneOffCosts: array_map(fn (array $c): array => [
                'atAge' => (int) $c['atAge'],
                'amount' => $this->moneyRequired($c['amount'] ?? null),
                'label' => (string) ($c['label'] ?? ''),
            ], $oneOffs),
        );
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
