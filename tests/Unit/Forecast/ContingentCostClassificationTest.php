<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use App\Forecast\ResultPresenter;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Money\Money;

/**
 * Option (b): the assembler auto-classifies each expense line's condition by label (with a
 * per-line override), and aggregates the contingent ones into the engine `ExpenseProfile`'s
 * propertyCosts / employmentCosts markers. The trust properties: housing/commute labels are
 * recognised, an explicit override wins over the auto-classification, the markers stay a
 * subset of the full essential total (no drift), and *saved* self-investment is never a
 * contingent cost (it is not spend).
 */
final class ContingentCostClassificationTest extends TestCase
{
    /** @param  list<array<string, mixed>>  $lines */
    private function profile(array $lines): ExpenseProfile
    {
        return (new HouseholdAssembler)->household([
            'householdName' => 'Contingent', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'expenseLines' => $lines,
            'expense' => ['survivorFactor' => '70'],
        ])->expenseProfile;
    }

    public function test_labels_auto_classify_into_property_and_employment_costs(): void
    {
        $p = $this->profile([
            ['id' => 'e1', 'label' => 'Food and bills', 'amount' => '12000', 'category' => 'essential'],
            ['id' => 'e2', 'label' => 'Mortgage payment', 'amount' => '14000', 'category' => 'essential'],
            ['id' => 'e3', 'label' => 'Service charge', 'amount' => '3000', 'category' => 'essential'],
            ['id' => 'e4', 'label' => 'Commuting / season ticket', 'amount' => '2000', 'category' => 'essential'],
        ]);

        // Mortgage + service charge → while-owning (property); commute → while-working.
        $this->assertSame(Money::fromPounds(17_000)->pence, $p->propertyCosts()->pence);
        $this->assertSame(Money::fromPounds(2_000)->pence, $p->employmentCosts()->pence);
        // Still counted in the full essential total — a marked subset, not removed (no drift).
        $this->assertSame(Money::fromPounds(31_000)->pence, $p->essentialAnnualSpend->pence);
    }

    public function test_an_explicit_condition_overrides_the_auto_classification(): void
    {
        // A "Mortgage" the user marks always-on stays in spend in every variant — not a property
        // cost — so the override wins over the label heuristic.
        $p = $this->profile([
            ['id' => 'e1', 'label' => 'Mortgage', 'amount' => '14000', 'category' => 'essential', 'condition' => 'always'],
        ]);

        $this->assertSame(0, $p->propertyCosts()->pence);
    }

    public function test_saved_self_investment_is_never_a_contingent_cost(): void
    {
        // A *saved* self-investment line builds net worth (it is a contribution, not spend), so
        // even a housing-ish label does not make it a property cost.
        $p = $this->profile([
            ['id' => 'e1', 'label' => 'Food', 'amount' => '12000', 'category' => 'essential'],
            ['id' => 's1', 'label' => 'Mortgage overpayment fund', 'amount' => '5000', 'category' => 'self_investment', 'savedAsAsset' => true],
        ]);

        $this->assertSame(0, $p->propertyCosts()->pence);
    }

    public function test_plsa_comparable_spend_excludes_property_costs(): void
    {
        // PLSA assumes outright ownership, so a mortgage line must not inflate the comparable
        // spend — it is excluded on the same basis the contingent-cost rule treats it.
        $household = (new HouseholdAssembler)->household([
            'householdName' => 'Plsa', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'expenseLines' => [
                ['id' => 'e1', 'label' => 'Living costs', 'amount' => '20000', 'category' => 'essential'],
                ['id' => 'e2', 'label' => 'Mortgage', 'amount' => '10000', 'category' => 'essential'],
            ],
            'expense' => ['survivorFactor' => '70'],
        ]);

        $plsa = ResultPresenter::plsaBenchmark($household);
        $this->assertNotNull($plsa);
        // Comparable spend = £20,000 living costs, NOT £30,000 — the mortgage is excluded.
        $this->assertSame(Money::fromPounds(20_000)->format(), $plsa['comparableSpend']);
    }
}
