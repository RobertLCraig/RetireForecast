<?php

declare(strict_types=1);

namespace App\Demo;

use App\Forecast\BuilderStateDelta;

/**
 * The obviously-fictional sample household behind the demo preset. It exists so a
 * fresh local install can show a complete, runnable forecast immediately, without
 * anyone entering real data — honouring the locked decision that no client data lives
 * in the repo and any first-run sample must be obviously fictional (DECISIONS
 * 2026-06-25).
 *
 * This is the single home for the demo's figures. {@see baseState()} is a full builder
 * form-state in the one canonical shape, so it assembles to the engine DTOs and runs
 * exactly like a user-built scenario (it is not a separate, parallel representation
 * that could drift). {@see retireEarlyState()} is the same plan with one value-only
 * lever moved, from which the seeder derives a delta-child what-if (via the one
 * {@see BuilderStateDelta} merge function) to showcase Compare.
 *
 * Every name and the household label carry "(fictional demo)" so the sample can never
 * be read as a real couple. Figures are deliberately round and illustrative.
 */
final class DemoScenario
{
    public const HOUSEHOLD_NAME = 'The Sample household (fictional demo)';

    public const BASE_NAME = 'Sample plan: sell up and buy a smaller home';

    public const CHILD_NAME = 'What-if: retire two years earlier';

    /**
     * The full builder form-state for the demo base plan, in the canonical shape the
     * scenario builder produces (scenario-level fields + the household form-state).
     *
     * @return array<string, mixed>
     */
    public static function baseState(): array
    {
        return [
            'step' => 5,
            'name' => self::BASE_NAME,
            'baseTaxYear' => '2026-27',
            'variant' => 'buy_outright',
            'ihtModelled' => true,
            'assumptionSetId' => null,
            'householdName' => self::HOUSEHOLD_NAME,
            'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'name' => 'Sam Sample (fictional)', 'dob' => '1962-05-15', 'sex' => 'male',
                    'employmentStatus' => 'employed', 'grossSalary' => '55000', 'salaryGrowth' => '2.5',
                    'plannedRetirementAge' => '66', 'niCategory' => 'A', 'longevityMode' => 'peer', 'longevityValue' => ''],
                ['id' => 'p2', 'name' => 'Jo Sample (fictional)', 'dob' => '1960-09-10', 'sex' => 'female',
                    'employmentStatus' => 'retired', 'grossSalary' => '', 'salaryGrowth' => '',
                    'plannedRetirementAge' => '', 'niCategory' => '', 'longevityMode' => 'peer', 'longevityValue' => ''],
            ],
            'expense' => ['essential' => '', 'discretionary' => '', 'survivorFactor' => '70'],
            'expenseLines' => [
                ['id' => 'ess1', 'label' => 'Household essentials', 'amount' => '24000', 'category' => 'essential', 'savedAsAsset' => false],
                ['id' => 'disc1', 'label' => 'Holidays & leisure', 'amount' => '9000', 'category' => 'discretionary', 'savedAsAsset' => false],
                ['id' => 'si1', 'label' => 'Evening course', 'amount' => '1200', 'category' => 'self_investment', 'savedAsAsset' => false],
            ],
            'oneOffCosts' => [
                ['id' => 'oneoff1', 'atAge' => '80', 'amount' => '20000', 'label' => 'New car / home adaptation'],
            ],
            'pensions' => [
                ['id' => 'dc1', 'ownerId' => 'p1', 'subtype' => 'dc', 'currentValue' => '350000', 'ongoingContribution' => '6000',
                    'employerContribution' => '3000', 'earliestAccessAge' => '57', 'pclsTakenToDate' => '0',
                    'growthAssumptionOverride' => '', 'withdrawals' => [
                        ['id' => 'wd1', 'kind' => 'pcls', 'amount' => '80000', 'atAge' => '66'],
                        ['id' => 'wd2', 'kind' => 'drawdown', 'amount' => '18000', 'atAge' => '67'],
                    ]],
                ['id' => 'db1', 'ownerId' => 'p2', 'subtype' => 'db', 'accruedAnnualPension' => '12000', 'normalRetirementAge' => '65',
                    'revaluationBasis' => 'cpi', 'escalationInPayment' => 'cpi_capped_5', 'spousePensionFraction' => '50',
                    'commutationLumpSum' => '', 'commutationFactor' => ''],
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '241.30', 'qualifyingYears' => '', 'deferralWeeks' => '0'],
                ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '', 'qualifyingYears' => '35', 'deferralWeeks' => '0'],
            ],
            'accounts' => [
                ['id' => 'acc1', 'ownerId' => 'p1', 'type' => 'isa', 'balance' => '60000', 'unrealisedGain' => '', 'yield' => '3'],
                ['id' => 'acc2', 'ownerId' => 'p2', 'type' => 'cash', 'balance' => '25000', 'unrealisedGain' => '', 'yield' => '1'],
                ['id' => 'acc3', 'ownerId' => 'p1', 'type' => 'gia', 'balance' => '30000', 'unrealisedGain' => '5000', 'yield' => '2'],
            ],
            'incomeStreams' => [],
            'hasProperty' => true,
            'property' => ['currentValue' => '450000', 'ownership' => 'mortgaged', 'everLet' => false,
                'outstandingMortgage' => '30000', 'runningCosts' => '5000', 'growthAssumptionOverride' => '', 'ownershipShare' => '100'],
            'housing' => ['salePrice' => '450000', 'buyPrice' => '280000', 'annualRent' => '15000',
                'rentInflationReal' => '0.5', 'movingCosts' => '8000',
                'sellingCosts' => [
                    'estate_agent' => ['label' => 'Estate agent', 'basis' => 'percent', 'value' => '1.25'],
                    'legal' => ['label' => 'Legal / conveyancing', 'basis' => 'fixed', 'value' => '1500'],
                    'epc_removals' => ['label' => 'EPC & removals', 'basis' => 'fixed', 'value' => '800'],
                ]],
        ];
    }

    /**
     * The demo base plan with a single value-only lever moved: the working partner
     * retires at 64 instead of 66. The seeder diffs this against {@see baseState()} to
     * derive the delta-child what-if, so the change is stored and merged exactly as a
     * user-built what-if would be (one source, no fork).
     *
     * @return array<string, mixed>
     */
    public static function retireEarlyState(): array
    {
        $state = self::baseState();
        $state['name'] = self::CHILD_NAME;
        $state['people'][0]['plannedRetirementAge'] = '64';

        return $state;
    }
}
