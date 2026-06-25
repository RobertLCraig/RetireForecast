<?php

declare(strict_types=1);

namespace App\Finance\Mapping;

use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\DbPension;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\IncomeStream;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Pension;
use RetireForecast\FinanceEngine\Dto\PensionEscalationBasis;
use RetireForecast\FinanceEngine\Dto\PensionType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Dto\WithdrawalInstruction;
use RetireForecast\FinanceEngine\Pension\WithdrawalKind;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;

/**
 * Maps the engine's {@see Household} DTO (and everything nested under it) to and
 * from the plain array stored as a Household's encrypted payload, and back again.
 *
 * The DTO is the single source of truth for the shape; this is the one place the
 * app turns it into storage and back. $name and $region are NOT part of the
 * payload: they are kept as clear structural columns for listing and filtering, so
 * {@see self::hydrate()} takes them back in alongside the decrypted payload.
 */
final class HouseholdMapper
{
    /** The sensitive, encrypted-at-rest part of a household: everyone and everything they own. */
    public static function payload(Household $household): array
    {
        return [
            'persons' => array_map(self::personToArray(...), $household->persons),
            'pensions' => array_map(self::pensionToArray(...), $household->pensions),
            'accounts' => array_map(self::accountToArray(...), $household->accounts),
            'incomeStreams' => array_map(self::incomeStreamToArray(...), $household->incomeStreams),
            'expenseProfile' => self::expenseProfileToArray($household->expenseProfile),
            'primaryResidence' => $household->primaryResidence === null
                ? null
                : self::propertyToArray($household->primaryResidence),
        ];
    }

    public static function hydrate(string $name, RegionProfile $region, array $payload): Household
    {
        return new Household(
            name: $name,
            region: $region,
            persons: array_map(self::personFromArray(...), $payload['persons']),
            expenseProfile: self::expenseProfileFromArray($payload['expenseProfile']),
            pensions: array_map(self::pensionFromArray(...), $payload['pensions']),
            accounts: array_map(self::accountFromArray(...), $payload['accounts']),
            incomeStreams: array_map(self::incomeStreamFromArray(...), $payload['incomeStreams']),
            primaryResidence: $payload['primaryResidence'] === null
                ? null
                : self::propertyFromArray($payload['primaryResidence']),
        );
    }

    private static function personToArray(Person $person): array
    {
        return [
            'id' => $person->id,
            'name' => $person->name,
            'dob' => Codec::dateString($person->dob),
            'sex' => $person->sex->value,
            'employmentStatus' => $person->employmentStatus->value,
            'grossSalary' => Codec::penceOrNull($person->grossSalary),
            'salaryGrowth' => Codec::bpsOrNull($person->salaryGrowth),
            'plannedRetirementAge' => $person->plannedRetirementAge,
            'niCategory' => $person->niCategory,
        ];
    }

    private static function personFromArray(array $data): Person
    {
        return new Person(
            id: $data['id'],
            dob: Codec::date($data['dob']),
            sex: Sex::from($data['sex']),
            employmentStatus: EmploymentStatus::from($data['employmentStatus']),
            grossSalary: Codec::moneyOrNull($data['grossSalary']),
            salaryGrowth: Codec::percentOrNull($data['salaryGrowth']),
            plannedRetirementAge: $data['plannedRetirementAge'],
            niCategory: $data['niCategory'],
            name: $data['name'] ?? null, // ?? for households stored before names existed
        );
    }

    private static function pensionToArray(Pension $pension): array
    {
        return match (true) {
            $pension instanceof DcPension => [
                'subtype' => PensionType::DefinedContribution->value,
                'ownerId' => $pension->ownerId,
                'currentValue' => Codec::pence($pension->currentValue),
                'ongoingContribution' => Codec::pence($pension->ongoingContribution),
                'employerContribution' => Codec::pence($pension->employerContribution),
                'earliestAccessAge' => $pension->earliestAccessAge,
                'withdrawalPlan' => array_map(self::withdrawalToArray(...), $pension->withdrawalPlan),
                'pclsTakenToDate' => Codec::penceOrNull($pension->pclsTakenToDate),
                'growthAssumptionOverride' => Codec::bpsOrNull($pension->growthAssumptionOverride),
            ],
            $pension instanceof DbPension => [
                'subtype' => PensionType::DefinedBenefit->value,
                'ownerId' => $pension->ownerId,
                'accruedAnnualPension' => Codec::pence($pension->accruedAnnualPension),
                'normalRetirementAge' => $pension->normalRetirementAge,
                'revaluationBasis' => $pension->revaluationBasis->value,
                'escalationInPayment' => $pension->escalationInPayment->value,
                'spousePensionFraction' => Codec::bpsOrNull($pension->spousePensionFraction),
                'commutationLumpSum' => Codec::penceOrNull($pension->commutationLumpSum),
                'commutationFactor' => Codec::bpsOrNull($pension->commutationFactor),
            ],
            $pension instanceof StatePensionEntitlement => [
                'subtype' => PensionType::State->value,
                'ownerId' => $pension->ownerId,
                'weeklyForecast' => Codec::penceOrNull($pension->weeklyForecast),
                'qualifyingYears' => $pension->qualifyingYears,
                'deferralWeeks' => $pension->deferralWeeks,
            ],
            default => throw new \InvalidArgumentException(
                'Unknown pension DTO: '.$pension::class,
            ),
        };
    }

    private static function pensionFromArray(array $data): Pension
    {
        return match (PensionType::from($data['subtype'])) {
            PensionType::DefinedContribution => new DcPension(
                ownerId: $data['ownerId'],
                currentValue: Codec::money($data['currentValue']),
                ongoingContribution: Codec::money($data['ongoingContribution']),
                employerContribution: Codec::money($data['employerContribution']),
                earliestAccessAge: $data['earliestAccessAge'],
                withdrawalPlan: array_map(self::withdrawalFromArray(...), $data['withdrawalPlan']),
                pclsTakenToDate: Codec::moneyOrNull($data['pclsTakenToDate']),
                growthAssumptionOverride: Codec::percentOrNull($data['growthAssumptionOverride']),
            ),
            PensionType::DefinedBenefit => new DbPension(
                ownerId: $data['ownerId'],
                accruedAnnualPension: Codec::money($data['accruedAnnualPension']),
                normalRetirementAge: $data['normalRetirementAge'],
                revaluationBasis: PensionEscalationBasis::from($data['revaluationBasis']),
                escalationInPayment: PensionEscalationBasis::from($data['escalationInPayment']),
                spousePensionFraction: Codec::percentOrNull($data['spousePensionFraction']),
                commutationLumpSum: Codec::moneyOrNull($data['commutationLumpSum']),
                commutationFactor: Codec::percentOrNull($data['commutationFactor']),
            ),
            PensionType::State => new StatePensionEntitlement(
                ownerId: $data['ownerId'],
                weeklyForecast: Codec::moneyOrNull($data['weeklyForecast']),
                qualifyingYears: $data['qualifyingYears'],
                deferralWeeks: $data['deferralWeeks'],
            ),
        };
    }

    private static function withdrawalToArray(WithdrawalInstruction $instruction): array
    {
        return [
            'kind' => $instruction->kind->name,
            'amount' => Codec::pence($instruction->amount),
            'atAge' => $instruction->atAge,
        ];
    }

    private static function withdrawalFromArray(array $data): WithdrawalInstruction
    {
        return new WithdrawalInstruction(
            kind: self::withdrawalKind($data['kind']),
            amount: Codec::money($data['amount']),
            atAge: $data['atAge'],
        );
    }

    /** {@see WithdrawalKind} is a pure (unbacked) enum, so it (de)serialises by case name. */
    private static function withdrawalKind(string $name): WithdrawalKind
    {
        foreach (WithdrawalKind::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        throw new \InvalidArgumentException("Unknown withdrawal kind: {$name}");
    }

    private static function accountToArray(Account $account): array
    {
        return [
            'ownerId' => $account->ownerId,
            'type' => $account->type->value,
            'balance' => Codec::pence($account->balance),
            'unrealisedGain' => Codec::penceOrNull($account->unrealisedGain),
            'yield' => Codec::bpsOrNull($account->yield),
        ];
    }

    private static function accountFromArray(array $data): Account
    {
        return new Account(
            ownerId: $data['ownerId'],
            type: AccountType::from($data['type']),
            balance: Codec::money($data['balance']),
            unrealisedGain: Codec::moneyOrNull($data['unrealisedGain']),
            yield: Codec::percentOrNull($data['yield']),
        );
    }

    private static function incomeStreamToArray(IncomeStream $stream): array
    {
        return [
            'ownerId' => $stream->ownerId,
            'type' => $stream->type->value,
            'grossAnnual' => Codec::pence($stream->grossAnnual),
            'taxable' => $stream->taxable,
            'inflationLinked' => $stream->inflationLinked,
            'startAge' => $stream->startAge,
            'endAge' => $stream->endAge,
        ];
    }

    private static function incomeStreamFromArray(array $data): IncomeStream
    {
        return new IncomeStream(
            ownerId: $data['ownerId'],
            type: IncomeStreamType::from($data['type']),
            grossAnnual: Codec::money($data['grossAnnual']),
            taxable: $data['taxable'],
            inflationLinked: $data['inflationLinked'],
            startAge: $data['startAge'],
            endAge: $data['endAge'],
        );
    }

    private static function expenseProfileToArray(ExpenseProfile $profile): array
    {
        return [
            'essentialAnnualSpend' => Codec::pence($profile->essentialAnnualSpend),
            'discretionaryAnnualSpend' => Codec::pence($profile->discretionaryAnnualSpend),
            'survivorSpendFactor' => Codec::bps($profile->survivorSpendFactor),
            'oneOffCosts' => array_map(
                static fn (array $cost): array => [
                    'atAge' => $cost['atAge'],
                    'amount' => Codec::pence($cost['amount']),
                    'label' => $cost['label'],
                ],
                $profile->oneOffCosts,
            ),
        ];
    }

    private static function expenseProfileFromArray(array $data): ExpenseProfile
    {
        return new ExpenseProfile(
            essentialAnnualSpend: Codec::money($data['essentialAnnualSpend']),
            discretionaryAnnualSpend: Codec::money($data['discretionaryAnnualSpend']),
            survivorSpendFactor: Codec::percent($data['survivorSpendFactor']),
            oneOffCosts: array_map(
                static fn (array $cost): array => [
                    'atAge' => $cost['atAge'],
                    'amount' => Codec::money($cost['amount']),
                    'label' => $cost['label'],
                ],
                $data['oneOffCosts'],
            ),
        );
    }

    private static function propertyToArray(Property $property): array
    {
        return [
            'currentValue' => Codec::pence($property->currentValue),
            'ownership' => $property->ownership->value,
            'isPrimaryResidence' => $property->isPrimaryResidence,
            'everLet' => $property->everLet,
            'outstandingMortgage' => Codec::penceOrNull($property->outstandingMortgage),
            'runningCosts' => Codec::penceOrNull($property->runningCosts),
            'growthAssumptionOverride' => Codec::bpsOrNull($property->growthAssumptionOverride),
            'ownershipShare' => Codec::bpsOrNull($property->ownershipShare),
        ];
    }

    private static function propertyFromArray(array $data): Property
    {
        return new Property(
            currentValue: Codec::money($data['currentValue']),
            ownership: OwnershipType::from($data['ownership']),
            isPrimaryResidence: $data['isPrimaryResidence'],
            everLet: $data['everLet'],
            outstandingMortgage: Codec::moneyOrNull($data['outstandingMortgage']),
            runningCosts: Codec::moneyOrNull($data['runningCosts']),
            growthAssumptionOverride: Codec::percentOrNull($data['growthAssumptionOverride']),
            ownershipShare: Codec::percentOrNull($data['ownershipShare']),
        );
    }
}
