<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Phase C1 data-layer integrity. The 3-tier line items are the single source of spend:
 *  - the essential/discretionary totals reconcile to the exact sum of their lines
 *    (no stored total that could drift — the reconciliation invariant);
 *  - a *saved* self-investment line lives once, as a contribution to net worth, never
 *    also as spend or as an account balance (one home per pound — gotcha O);
 *  - and that saved/spent distinction demonstrably reaches the forecast: saving a line
 *    builds more wealth than spending it (completeness — no silent drop).
 */
class ExpenseLineReconciliationTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $expenseLines
     * @return array<string, mixed>
     */
    private function state(array $expenseLines): array
    {
        return [
            'householdName' => 'Reconciliation', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1956-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'pensions' => [['id' => 'db1', 'ownerId' => 'p1', 'subtype' => 'db', 'accruedAnnualPension' => '40000',
                'normalRetirementAge' => '60', 'revaluationBasis' => 'cpi', 'escalationInPayment' => 'cpi']],
            'accounts' => [['id' => 'a1', 'ownerId' => 'p1', 'type' => 'isa', 'balance' => '50000']],
            'expenseLines' => $expenseLines,
            'expense' => ['survivorFactor' => '100'],
        ];
    }

    public function test_derived_totals_reconcile_to_the_exact_sum_of_the_lines(): void
    {
        $household = (new HouseholdAssembler)->household($this->state([
            ['id' => 'l1', 'amount' => '12000.50', 'category' => 'essential'],
            ['id' => 'l2', 'amount' => '3000', 'category' => 'essential'],
            ['id' => 'l3', 'amount' => '4000', 'category' => 'discretionary'],
            ['id' => 'l4', 'amount' => '1000', 'category' => 'self_investment', 'savedAsAsset' => false],
            ['id' => 'l5', 'amount' => '2000', 'category' => 'self_investment', 'savedAsAsset' => true],
        ]));

        // Essential floor = exact pence sum of the essential lines (12000.50 + 3000).
        $this->assertSame(1_500_050, $household->expenseProfile->essentialAnnualSpend->pence);
        // Discretionary = discretionary lines + *spent* self-investment (4000 + 1000).
        $this->assertSame(500_000, $household->expenseProfile->discretionaryAnnualSpend->pence);

        // The *saved* line (2000) lives once, as a balance-zero contributing account —
        // never as spend, never as an opening balance.
        $contributing = array_values(array_filter($household->accounts, fn ($a): bool => $a->ongoingContributions !== null));
        $this->assertCount(1, $contributing);
        $this->assertSame(200_000, $contributing[0]->ongoingContributions->pence);
        $this->assertSame(0, $contributing[0]->balance->pence);
    }

    public function test_saving_a_self_investment_line_builds_more_wealth_than_spending_it(): void
    {
        $assembler = new HouseholdAssembler;
        $saved = $assembler->household($this->state([
            ['id' => 'e', 'amount' => '15000', 'category' => 'essential'],
            ['id' => 's', 'amount' => '5000', 'category' => 'self_investment', 'savedAsAsset' => true],
        ]));
        $spent = $assembler->household($this->state([
            ['id' => 'e', 'amount' => '15000', 'category' => 'essential'],
            ['id' => 's', 'amount' => '5000', 'category' => 'self_investment', 'savedAsAsset' => false],
        ]));

        $forecaster = new DeterministicForecaster(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi),
            new CohortLifeTable,
        );
        $assumptions = AssumptionSetLibrary::default();
        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');

        $savedTerminal = $forecaster->forecast($saved, $assumptions, $settings)->terminalUsableWealth->pence;
        $spentTerminal = $forecaster->forecast($spent, $assumptions, $settings)->terminalUsableWealth->pence;

        // The saved £5,000 builds net worth; the spent £5,000 is consumed — so the saved
        // path ends with strictly more usable wealth. The flag reaches the result.
        $this->assertGreaterThan($spentTerminal, $savedTerminal);
    }
}
