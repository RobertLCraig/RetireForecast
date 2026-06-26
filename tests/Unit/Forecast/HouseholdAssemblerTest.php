<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuilderStateFixture;
use Tests\Support\HouseholdFixture;

/**
 * The builder is the third consumer of the one canonical shape. This proves the
 * assembly is lossless: form-shaped strings covering every nested DTO and optional
 * field rebuild exactly the rich {@see HouseholdFixture} household and housing
 * action, pounds parsed to exact pence with no float drift.
 */
class HouseholdAssemblerTest extends TestCase
{
    public function test_it_rebuilds_the_full_household_dto_from_form_state(): void
    {
        $assembled = (new HouseholdAssembler)->assemble(BuilderStateFixture::full());

        $this->assertEquals(HouseholdFixture::household(), $assembled['household']);
        $this->assertEquals(HouseholdFixture::housingAction(), $assembled['housingAction']);
    }

    public function test_pounds_and_pence_parse_to_exact_pence(): void
    {
        $household = (new HouseholdAssembler)->household([
            'householdName' => 'X',
            'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1960-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expense' => ['essential' => '28000.50', 'discretionary' => '', 'survivorFactor' => ''],
            'pensions' => [['ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230.25']],
        ]);

        $this->assertSame(2_800_050, $household->expenseProfile->essentialAnnualSpend->pence);
        $this->assertSame(23_025, $household->pensions[0]->weeklyForecast->pence);
    }

    public function test_expense_line_items_derive_the_essential_and_discretionary_totals(): void
    {
        // Essential = sum of essential lines; discretionary = discretionary lines plus
        // *spent* self-investment (consumption that does not build an asset).
        $household = (new HouseholdAssembler)->household([
            'householdName' => 'X',
            'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1960-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expenseLines' => [
                ['id' => 'l1', 'label' => 'Bills', 'amount' => '20000', 'category' => 'essential'],
                ['id' => 'l2', 'label' => 'Food', 'amount' => '5000', 'category' => 'essential'],
                ['id' => 'l3', 'label' => 'Holidays', 'amount' => '8000', 'category' => 'discretionary'],
                ['id' => 'l4', 'label' => 'A course', 'amount' => '2000', 'category' => 'self_investment', 'savedAsAsset' => false],
            ],
        ]);

        $this->assertSame(2_500_000, $household->expenseProfile->essentialAnnualSpend->pence);
        $this->assertSame(1_000_000, $household->expenseProfile->discretionaryAnnualSpend->pence);
        $this->assertSame([], $household->accounts); // nothing saved, so no synthetic account
    }

    public function test_saved_self_investment_becomes_a_contributing_account_not_spend(): void
    {
        // A *saved* self-investment line builds net worth: it is not counted as spend,
        // and appears once as a contributing (balance-zero) account — one home per pound.
        $household = (new HouseholdAssembler)->household([
            'householdName' => 'X',
            'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1960-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expenseLines' => [
                ['id' => 'l1', 'label' => 'Bills', 'amount' => '20000', 'category' => 'essential'],
                ['id' => 'l2', 'label' => 'Savings plan', 'amount' => '3000', 'category' => 'self_investment', 'savedAsAsset' => true],
            ],
        ]);

        $this->assertSame(2_000_000, $household->expenseProfile->essentialAnnualSpend->pence);
        $this->assertSame(0, $household->expenseProfile->discretionaryAnnualSpend->pence);

        $this->assertCount(1, $household->accounts);
        $saved = $household->accounts[0];
        $this->assertSame(0, $saved->balance->pence);
        $this->assertSame(300_000, $saved->ongoingContributions->pence);
    }
}
