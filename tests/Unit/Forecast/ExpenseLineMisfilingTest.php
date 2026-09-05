<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Money\Money;

/**
 * Card 0033. Two ways a spend line was filed under the wrong heading, both of which flatter a
 * result:
 *
 * 1. **Utilities hidden inside a service charge.** The whole charge is a `while_owning_home` cost,
 *    so a sale deleted the water and the electricity with it — and the household still has to heat
 *    and plumb wherever it lives next.
 * 2. **Home insurance filed as discretionary.** Cover a lender requires is not a nice-to-have, and
 *    sitting outside the essential floor flattered the "essentials always met" probability on every
 *    scenario.
 */
final class ExpenseLineMisfilingTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function profile(array $lines): ExpenseProfile
    {
        return (new HouseholdAssembler)->household([
            'householdName' => 'Filers', 'region' => 'england_wales_ni', 'baseTaxYear' => '2026-27',
            'people' => [['id' => 'p1', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'expenseLines' => $lines,
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => false,
        ])->expenseProfile;
    }

    public function test_the_utilities_inside_a_service_charge_reach_the_engine(): void
    {
        // The reader says how much of the £4,000 charge buys water and electricity. That figure has
        // to arrive on the profile, or the engine has nothing to carry across a sale.
        $profile = $this->profile([
            ['id' => 'e1', 'label' => 'Living costs', 'amount' => '18000', 'category' => 'essential'],
            ['id' => 'e2', 'label' => 'Service charge', 'amount' => '4000', 'category' => 'essential', 'utilities' => '1500'],
        ]);

        $this->assertSame(Money::fromPounds(4_000)->pence, $profile->propertyCosts()->pence);
        $this->assertSame(Money::fromPounds(1_500)->pence, $profile->propertyCostsUtilities()->pence);
        // £22,000 of essentials, of which the sale removes £2,500 — the charge less its utilities.
        $this->assertSame(Money::fromPounds(22_000)->pence, $profile->essentialAnnualSpend->pence);
        $this->assertSame(Money::fromPounds(19_500)->pence, $profile->withoutPropertyCosts()->essentialAnnualSpend->pence);
    }

    public function test_utilities_entered_on_a_line_that_is_not_a_home_ownership_cost_are_ignored(): void
    {
        // The figure only means anything on a cost that DIES with the home. An always-charged line
        // is never stripped, so nothing needs carrying across and nothing must be double-counted.
        $profile = $this->profile([
            ['id' => 'e1', 'label' => 'Groceries', 'amount' => '6000', 'category' => 'essential', 'utilities' => '1500'],
        ]);

        $this->assertSame(0, $profile->propertyCostsUtilities()->pence);
    }

    public function test_home_insurance_is_essential_even_when_the_reader_filed_it_as_discretionary(): void
    {
        // Buildings cover is a condition of every mortgage, and contents cover replaces things the
        // household cannot do without. Whatever tier it was typed into, it belongs in the floor the
        // "essentials always met" measure is read against.
        $profile = $this->profile([
            ['id' => 'e1', 'label' => 'Living costs', 'amount' => '18000', 'category' => 'essential'],
            ['id' => 'e2', 'label' => 'Buildings and contents insurance', 'amount' => '600', 'category' => 'discretionary'],
            ['id' => 'e3', 'label' => 'Holidays', 'amount' => '3000', 'category' => 'discretionary'],
        ]);

        $this->assertSame(Money::fromPounds(18_600)->pence, $profile->essentialAnnualSpend->pence);
        $this->assertSame(Money::fromPounds(3_000)->pence, $profile->discretionaryAnnualSpend->pence);
        // Reconciliation: nothing is created or lost by the reclassification, only moved.
        $this->assertSame(Money::fromPounds(21_600)->pence, $profile->targetAnnualSpend()->pence);
    }

    public function test_insurance_that_is_not_cover_of_the_home_is_left_where_the_reader_put_it(): void
    {
        // No over-reach. Pet, travel and gadget cover are genuinely discretionary, and moving them
        // would overstate the floor every capacity-for-loss reading is taken against.
        $profile = $this->profile([
            ['id' => 'e1', 'label' => 'Living costs', 'amount' => '18000', 'category' => 'essential'],
            ['id' => 'e2', 'label' => 'Pet insurance', 'amount' => '400', 'category' => 'discretionary'],
            ['id' => 'e3', 'label' => 'Travel insurance', 'amount' => '200', 'category' => 'discretionary'],
        ]);

        $this->assertSame(Money::fromPounds(18_000)->pence, $profile->essentialAnnualSpend->pence);
        $this->assertSame(Money::fromPounds(600)->pence, $profile->discretionaryAnnualSpend->pence);
    }

    public function test_a_line_the_reader_switched_off_is_not_reclassified_into_the_floor(): void
    {
        // An excluded line contributes nothing to any total, and that must hold for a line the
        // insurance rule would otherwise have promoted.
        $profile = $this->profile([
            ['id' => 'e1', 'label' => 'Living costs', 'amount' => '18000', 'category' => 'essential'],
            ['id' => 'e2', 'label' => 'Home insurance', 'amount' => '600', 'category' => 'discretionary', 'included' => false],
        ]);

        $this->assertSame(Money::fromPounds(18_000)->pence, $profile->essentialAnnualSpend->pence);
        $this->assertSame(0, $profile->discretionaryAnnualSpend->pence);
    }
}
