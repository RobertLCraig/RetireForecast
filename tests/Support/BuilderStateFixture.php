<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Form-shaped state for the scenario builder, as strings exactly like the inputs
 * produce. {@see full()} reproduces the rich {@see HouseholdFixture} so the same
 * data drives both the assembler's losslessness test and the Livewire builder's
 * round-trip test. {@see minimalValid()} is the smallest state that passes
 * validation, for the validation tests to mutate.
 */
final class BuilderStateFixture
{
    /** @return array<string, mixed> */
    public static function full(): array
    {
        return [
            'householdName' => 'The Worked-Example Couple',
            'relationshipStatus' => 'married_or_civil_partnership',
            'region' => 'england_wales_ni',
            'people' => [
                // The death-in-service keys are present-but-blank on purpose: the builder BACKFILLS
                // them on load, so a fixture person without them would differ from the same person
                // after a round-trip and show up as a spurious what-if delta. Blank = no cover.
                // The will and residence keys are here for the same reason: false and blank are
                // what the builder backfills, so leaving them out would read as a what-if delta.
                ['id' => 'p1', 'dob' => '1961-04-02', 'sex' => 'male', 'employmentStatus' => 'employed',
                    'grossSalary' => '62000', 'salaryGrowth' => '2.5', 'plannedRetirementAge' => '66', 'niCategory' => 'A',
                    'deathInServiceMode' => '', 'deathInServiceMultiple' => '', 'deathInServiceSum' => '',
                    'hasWill' => false, 'ukLongTermResident' => ''],
                ['id' => 'p2', 'dob' => '1963-11-20', 'sex' => 'female', 'employmentStatus' => 'retired',
                    'grossSalary' => '', 'salaryGrowth' => '', 'plannedRetirementAge' => '', 'niCategory' => '',
                    'deathInServiceMode' => '', 'deathInServiceMultiple' => '', 'deathInServiceSum' => '',
                    'hasWill' => false, 'ukLongTermResident' => ''],
            ],
            'expense' => ['essential' => '', 'discretionary' => '', 'survivorFactor' => '70'],
            'expenseLines' => [
                ['id' => 'ess1', 'label' => 'Essentials', 'amount' => '28000', 'category' => 'essential', 'savedAsAsset' => false],
                ['id' => 'disc1', 'label' => 'Discretionary', 'amount' => '12500', 'category' => 'discretionary', 'savedAsAsset' => false],
            ],
            'oneOffCosts' => [['id' => 'oneoff1', 'atAge' => '80', 'amount' => '45000', 'label' => 'Care top-up']],
            // Every pension carries `fixedEscalationRate`, blank, for the same reason the people
            // carry their death-in-service keys: the builder backfills it on load, so a fixture
            // pension without it would differ from the same pension after a round-trip and show
            // up as a spurious what-if delta. Blank = the engine's disclosed default rate.
            'pensions' => [
                ['id' => 'dc1', 'ownerId' => 'p1', 'subtype' => 'dc', 'currentValue' => '410000', 'ongoingContribution' => '8000',
                    'employerContribution' => '4000', 'reliefMethod' => 'net_pay', 'earliestAccessAge' => '57', 'pclsTakenToDate' => '0',
                    'growthAssumptionOverride' => '4.5', 'fixedEscalationRate' => '', 'withdrawals' => [
                        ['id' => 'wd1', 'kind' => 'pcls', 'amount' => '100000', 'atAge' => '66'],
                        ['id' => 'wd2', 'kind' => 'ufpls', 'amount' => '20000', 'atAge' => '67'],
                        ['id' => 'wd3', 'kind' => 'drawdown', 'amount' => '15000', 'atAge' => '68'],
                    ]],
                ['id' => 'db1', 'ownerId' => 'p2', 'subtype' => 'db', 'accruedAnnualPension' => '9200', 'normalRetirementAge' => '65',
                    'revaluationBasis' => 'cpi', 'escalationInPayment' => 'cpi_capped_5', 'fixedEscalationRate' => '',
                    'spousePensionFraction' => '50', 'commutationLumpSum' => '30000', 'commutationFactor' => '12'],
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230.25', 'qualifyingYears' => '', 'deferralWeeks' => '0', 'fixedEscalationRate' => ''],
                ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '', 'qualifyingYears' => '34', 'deferralWeeks' => '8', 'fixedEscalationRate' => ''],
            ],
            'accounts' => [
                ['id' => 'acc1', 'ownerId' => 'p1', 'type' => 'isa', 'balance' => '85000', 'unrealisedGain' => '', 'yield' => '3'],
                ['id' => 'acc2', 'ownerId' => 'p2', 'type' => 'gia', 'balance' => '40000', 'unrealisedGain' => '6500', 'yield' => '2'],
                ['id' => 'acc3', 'ownerId' => 'p1', 'type' => 'cash', 'balance' => '20000', 'unrealisedGain' => '', 'yield' => ''],
            ],
            'incomeStreams' => [
                ['id' => 'inc1', 'ownerId' => 'p2', 'type' => 'rental', 'grossAnnual' => '7200', 'taxable' => true,
                    'inflationLinked' => true, 'startAge' => '60', 'endAge' => '90'],
            ],
            'capitalReceipts' => [
                ['id' => 'cr1', 'ownerId' => 'p1', 'year' => '2029', 'amount' => '90000', 'label' => 'Family gift'],
            ],
            'hasProperty' => true,
            'property' => ['currentValue' => '525000', 'ownership' => 'mortgaged', 'everLet' => false,
                // Present-but-blank on purpose, like the death-in-service keys above: the builder
                // BACKFILLS them on load, so a fixture property without them would differ from the
                // same property after a round trip and show as a spurious what-if delta.
                'isLet' => false, 'lettingManagementRate' => '', 'lettingVoidRate' => '', 'lettingMaintenanceRate' => '',
                'occupiedByQualifyingRelative' => false,
                'outstandingMortgage' => '48000', 'runningCosts' => '6400', 'growthAssumptionOverride' => '1', 'ownershipShare' => '100',
                'councilTax' => '2100', 'councilTaxDisabledBand' => '',
                'mortgageRedemptionYear' => '', 'mortgageMaturityAction' => 'refinance', 'mortgageRollUpRate' => '',
                'mortgageOverpayment' => '',
                'mortgageRepaymentTermMonths' => '', 'mortgageRepaymentStartYear' => '', 'mortgageRepaymentStartMonth' => '',
                'mortgageRepaymentRate' => '', 'mortgageRepaymentInitialMonths' => '', 'mortgageRepaymentRevertRate' => ''],
            'housing' => ['salePrice' => '525000', 'buyPrice' => '320000', 'annualRent' => '18000',
                'rentInflationReal' => '0.5', 'movingCosts' => '9500',
                'buyRunningCosts' => '', 'buyGrowthReal' => '',
                'sellingCosts' => ['estate_agent' => ['label' => 'Estate agent', 'basis' => 'percent', 'value' => '1.5']]],
        ];
    }

    /** @return array<string, mixed> The smallest state that passes builder validation. */
    public static function minimalValid(): array
    {
        return [
            'name' => 'My forecast',
            'householdName' => 'Test household',
            'region' => 'england_wales_ni',
            'baseTaxYear' => '2026-27',
            'variant' => 'rent',
            'people' => [
                ['id' => 'p1', 'dob' => '1955-01-01', 'sex' => 'female', 'employmentStatus' => 'retired',
                    'grossSalary' => '', 'salaryGrowth' => '', 'plannedRetirementAge' => '', 'niCategory' => ''],
            ],
            'expense' => ['essential' => '', 'discretionary' => '', 'survivorFactor' => '70'],
            'expenseLines' => [
                ['id' => 'ess1', 'label' => 'Essentials', 'amount' => '20000', 'category' => 'essential', 'savedAsAsset' => false],
            ],
            'hasProperty' => false,
            'housing' => ['salePrice' => '300000', 'buyPrice' => '', 'annualRent' => '', 'rentInflationReal' => '', 'movingCosts' => '', 'sellingCosts' => []],
        ];
    }
}
