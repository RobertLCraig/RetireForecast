<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\SpendPath;
use RetireForecast\FinanceEngine\Money\Money;

/**
 * Slice 4 of the spending smile: an age-banded expense line reaches the assembled profile, and
 * the aggregate essential/discretionary path is the exact sum of its line paths at *every* age
 * (the reconciliation invariant, extended from the flat scalar sum to a per-age sum). Only an
 * always-condition line may smile; a contingent cost stays flat.
 */
class SpendingSmileAssemblerTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $expenseLines
     * @return array<string, mixed>
     */
    private function state(array $expenseLines): array
    {
        return [
            'householdName' => 'Smile', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1956-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expenseLines' => $expenseLines,
            'expense' => ['survivorFactor' => '100'],
        ];
    }

    public function test_a_banded_line_reaches_the_assembled_discretionary_path(): void
    {
        $household = (new HouseholdAssembler)->household($this->state([
            ['id' => 'l1', 'label' => 'Bills', 'amount' => '12000', 'category' => 'essential'],
            ['id' => 'l2', 'label' => 'Holidays', 'amount' => '5000', 'category' => 'discretionary',
                'bands' => [['fromAge' => 75, 'amount' => '2000']]],
        ]));
        $profile = $household->expenseProfile;

        $this->assertTrue($profile->hasSmile());
        // The headline scalar is the start-band figure (unchanged shape for the rest of the app).
        $this->assertSame(500_000, $profile->discretionaryAnnualSpend->pence);
        // The discretionary spend steps at the band age; essentials stay flat.
        $this->assertSame(500_000, $profile->discretionaryAnnualSpendAt(70)->pence);
        $this->assertSame(200_000, $profile->discretionaryAnnualSpendAt(75)->pence);
        $this->assertSame(1_200_000, $profile->essentialAnnualSpendAt(70)->pence);
        $this->assertSame(1_200_000, $profile->essentialAnnualSpendAt(90)->pence);
    }

    public function test_the_aggregate_path_reconciles_to_the_sum_of_line_paths_at_every_age(): void
    {
        $household = (new HouseholdAssembler)->household($this->state([
            ['id' => 'd1', 'label' => 'Eating out', 'amount' => '4000', 'category' => 'discretionary'],
            ['id' => 'd2', 'label' => 'Holidays', 'amount' => '5000', 'category' => 'discretionary',
                'bands' => [['fromAge' => 75, 'amount' => '2000']]],
        ]));

        // The assembler's rule spelled out independently: base at age 0, then each band.
        $expected = SpendPath::flat(Money::fromPounds(4_000))->plus(
            SpendPath::fromBands([
                ['fromAge' => 0, 'amount' => Money::fromPounds(5_000)],
                ['fromAge' => 75, 'amount' => Money::fromPounds(2_000)],
            ]),
        );

        $this->assertTrue(
            $household->expenseProfile->discretionarySpendPath->equals($expected),
            'the assembled discretionary path does not reconcile to the sum of its line paths',
        );
        // And spot the arithmetic: £9k before the band, £6k from it.
        $this->assertSame(900_000, $household->expenseProfile->discretionaryAnnualSpendAt(74)->pence);
        $this->assertSame(600_000, $household->expenseProfile->discretionaryAnnualSpendAt(75)->pence);
    }

    public function test_a_flat_scenario_is_unchanged_and_carries_no_smile(): void
    {
        $household = (new HouseholdAssembler)->household($this->state([
            ['id' => 'l1', 'label' => 'Bills', 'amount' => '12000.50', 'category' => 'essential'],
            ['id' => 'l3', 'label' => 'Holidays', 'amount' => '4000', 'category' => 'discretionary'],
        ]));
        $profile = $household->expenseProfile;

        $this->assertFalse($profile->hasSmile());
        // The headline scalars are the exact pence sums, exactly as before the smile existed.
        $this->assertSame(1_200_050, $profile->essentialAnnualSpend->pence);
        $this->assertSame(400_000, $profile->discretionaryAnnualSpend->pence);
        $this->assertSame(400_000, $profile->discretionaryAnnualSpendAt(90)->pence);
    }

    public function test_a_contingent_line_does_not_smile_even_with_bands(): void
    {
        // A mortgage is essential + while_mortgaged (auto-classified), so it must stay flat: a
        // band on it is ignored (contingent costs do not fade with age — a flagged v1 limit).
        $household = (new HouseholdAssembler)->household($this->state([
            ['id' => 'm', 'label' => 'Mortgage', 'amount' => '8000', 'category' => 'essential',
                'bands' => [['fromAge' => 70, 'amount' => '1000']]],
        ]));
        $profile = $household->expenseProfile;

        $this->assertFalse($profile->hasSmile());
        $this->assertSame(800_000, $profile->essentialAnnualSpendAt(65)->pence);
        $this->assertSame(800_000, $profile->essentialAnnualSpendAt(90)->pence);
    }
}
