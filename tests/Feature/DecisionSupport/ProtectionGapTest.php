<?php

declare(strict_types=1);

namespace Tests\Feature\DecisionSupport;

use App\DecisionSupport\ProtectionGap;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuilderStateFixture;
use Tests\TestCase;

/**
 * The protection gap: what happens to the one who is left, and what would close it.
 *
 * The properties that make the answer worth showing:
 *  1. it is **relative to the couple's own plan** — a household whose joint plan already runs short
 *     must not be told it has a protection need for a shortfall a death did not cause;
 *  2. the solved sum genuinely does the job (the plan is restored AT it) and is genuinely near the
 *     minimum (a materially smaller sum does not restore it) — that is what makes it a threshold
 *     and not a guess;
 *  3. employer cover in force **reduces** the gap, and the panel reports the payout the forecast
 *     actually pays rather than a figure recomputed beside it;
 *  4. the **cliff** is real: the same death after retirement, when the cover has ceased, leaves a
 *     bigger hole than the same death while working.
 */
final class ProtectionGapTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $state */
    private function scenario(array $state): Scenario
    {
        $user = User::factory()->create();

        $scenario = new Scenario;
        $scenario->user_id = $user->id;
        $scenario->builder_state = $state;
        $scenario->projectFrom($state);
        $scenario->save();

        return $scenario;
    }

    /**
     * A couple whose plan leans on one salary: the working partner's death is a real event for the
     * survivor, which is the case the panel exists for. Stay-put so the housing variant is the
     * plain household.
     *
     * @param  array<string, mixed>  $coverFields
     * @return array<string, mixed>
     */
    private function state(array $coverFields = [], string $isa = '120000'): array
    {
        $state = BuilderStateFixture::full();
        $state['name'] = 'Protection';
        $state['baseTaxYear'] = '2026-27';
        $state['variant'] = 'stay_put';
        $state['people'] = [
            ['id' => 'p1', 'name' => 'Alex', 'dob' => '1966-04-02', 'sex' => 'male', 'employmentStatus' => 'employed',
                'grossSalary' => '45000', 'salaryGrowth' => '', 'plannedRetirementAge' => '67', 'niCategory' => 'A'] + $coverFields,
            ['id' => 'p2', 'name' => 'Sam', 'dob' => '1964-11-20', 'sex' => 'female', 'employmentStatus' => 'retired',
                'grossSalary' => '', 'salaryGrowth' => '', 'plannedRetirementAge' => '', 'niCategory' => ''],
        ];
        // Modest pots and no DB survivor pension, so the survivor really does lose income.
        $state['pensions'] = [
            ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230.25', 'qualifyingYears' => '', 'deferralWeeks' => '0'],
            ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '230.25', 'qualifyingYears' => '', 'deferralWeeks' => '0'],
        ];
        $state['accounts'] = [
            ['id' => 'acc1', 'ownerId' => 'p1', 'type' => 'isa', 'balance' => $isa, 'unrealisedGain' => '', 'yield' => ''],
        ];
        $state['incomeStreams'] = [];
        $state['capitalReceipts'] = [];
        $state['oneOffCosts'] = [];
        $state['expenseLines'] = [
            ['id' => 'ess1', 'label' => 'Essentials', 'amount' => '30000', 'category' => 'essential', 'savedAsAsset' => false],
        ];

        return $state;
    }

    private function gap(): ProtectionGap
    {
        return app(ProtectionGap::class);
    }

    /** @return array<string, mixed> the entry for the working partner */
    private function worker(array $result): array
    {
        foreach ($result['people'] as $person) {
            if ($person['id'] === 'p1') {
                return $person;
            }
        }

        $this->fail('no p1 in the protection result');
    }

    public function test_a_one_person_household_has_no_survivor_to_protect(): void
    {
        // Their question is about the estate, which the IHT panel answers. Returning a protection
        // gap here would be answering a question nobody asked.
        $state = $this->state();
        $state['people'] = [$state['people'][1]];
        $state['accounts'][0]['ownerId'] = 'p2';
        $state['pensions'] = [$state['pensions'][1]];

        $this->assertNull($this->gap()->forScenario($this->scenario($state)));
    }

    public function test_losing_the_earner_leaves_a_gap_and_the_solved_cover_closes_it(): void
    {
        $result = $this->gap()->forScenario($this->scenario($this->state()));
        $this->assertNotNull($result);

        $worker = $this->worker($result);
        $this->assertSame('Alex', $worker['name']);
        $this->assertSame('Sam', $worker['survivorName']);
        $this->assertTrue($worker['gap']->isPositive(), 'losing the salary should leave a real hole');
        $this->assertFalse($worker['gapCeilingHit']);
        $this->assertTrue($worker['worseThanBaseline']);
    }

    /**
     * The solved sum must be a real threshold: cover a little above it closes the gap, cover a
     * little below it does not. Anything that only proves "a big enough number works" would pass
     * for a solver that always returned its ceiling.
     *
     * Bracketed rather than tested for equality, because the two figures are honestly in different
     * units: a stated sum assured is NOMINAL (it is worth a little less by the time it lands),
     * while the solved need — like every figure on the results page — is today's money. A 10%
     * bracket is far wider than that wedge and far narrower than "any large number".
     *
     * The money cannot be handed over as an ordinary capital receipt instead: a receipt in the
     * builder state arrives whether or not anyone dies, so it would lift the BASELINE too and move
     * the very bar being measured. Death-contingent money has to enter as death-contingent cover.
     */
    public function test_the_solved_sum_is_a_threshold_not_a_guess(): void
    {
        $need = $this->worker($this->gap()->forScenario($this->scenario($this->state())) ?? [])['gap'];
        $this->assertTrue($need->isPositive());

        $atCover = fn (float $fraction): array => $this->worker(
            $this->gap()->forScenario($this->scenario($this->state([
                'deathInServiceMode' => 'fixed',
                'deathInServiceSum' => (string) (int) round($need->pence / 100 * $fraction),
            ]))) ?? []
        );

        $this->assertTrue($atCover(1.1)['gap']->isZero(), 'cover just above the solved sum closes the gap');
        $this->assertTrue($atCover(0.9)['gap']->isPositive(), 'cover just below it does not');
    }

    public function test_employer_cover_in_force_is_reported_from_the_forecast_and_shrinks_the_gap(): void
    {
        $without = $this->worker($this->gap()->forScenario($this->scenario($this->state())) ?? []);
        $with = $this->worker($this->gap()->forScenario($this->scenario($this->state([
            'deathInServiceMode' => 'multiple', 'deathInServiceMultiple' => '4',
        ]))) ?? []);

        // 4 x £45,000, read out of the stressed forecast's own death_in_service line — and so, like
        // every figure on the results page, in TODAY'S money: the payout lands a year out, so its
        // real value is a little under the nominal £180,000. That is the right unit to compare with
        // the solved need, which is likewise today's money.
        $this->assertEqualsWithDelta(180_000_00, $with['coverInForce']->pence, 5_000_00);
        $this->assertLessThan(180_000_00, $with['coverInForce']->pence, 'reported in real terms, not nominal');
        $this->assertSame('4x salary', $with['coverDescription']);
        $this->assertLessThan($without['gap']->pence, $with['gap']->pence, 'cover in force must shrink the gap');

        // And the "what if you had none" figure is the un-covered need, which is what the panel
        // uses to say how much work the employer's policy is doing.
        $this->assertSame($without['gap']->pence, $with['needWithoutCover']->pence);
    }

    public function test_the_cliff_when_employer_cover_ceases_at_retirement(): void
    {
        // The same death, one year after they retire, is met with nothing — so the hole is bigger.
        // This is the fact the panel exists to surface: the protection disappears exactly when the
        // household stops being able to replace it.
        $worker = $this->worker($this->gap()->forScenario($this->scenario($this->state([
            'deathInServiceMode' => 'multiple', 'deathInServiceMultiple' => '4',
        ]))) ?? []);

        $this->assertSame(1966 + 67, $worker['coverCeasesInYear']);
        $this->assertSame(1966 + 67 + 1, $worker['deathYearAfterRetirement']);
        $this->assertNotNull($worker['gapAfterCoverCeases']);
        $this->assertGreaterThan(
            $worker['gap']->pence,
            $worker['gapAfterCoverCeases']->pence,
            'losing the cover must leave a bigger hole than keeping it',
        );
    }

    public function test_no_cover_means_no_cliff_to_report(): void
    {
        $worker = $this->worker($this->gap()->forScenario($this->scenario($this->state())) ?? []);

        $this->assertNull($worker['coverCeasesInYear']);
        $this->assertNull($worker['gapAfterCoverCeases']);
        $this->assertNull($worker['coverDescription']);
    }

    public function test_a_wealthy_household_needs_no_cover_at_all(): void
    {
        // A survivor who is no worse off must be told £0, not a number. Reporting a protection need
        // that is not there is as wrong as missing one that is.
        $worker = $this->worker($this->gap()->forScenario($this->scenario($this->state(isa: '1500000'))) ?? []);

        $this->assertTrue($worker['gap']->isZero());
        $this->assertFalse($worker['worseThanBaseline']);
    }

    public function test_the_bar_is_the_households_own_plan_not_an_absolute_one(): void
    {
        // A plan that already runs short must not be handed a protection need for the part of the
        // shortfall a death did not cause: the gap is measured against the couple's own baseline.
        $broke = $this->gap()->forScenario($this->scenario($this->state(isa: '0')));
        $this->assertNotNull($broke);
        $this->assertNotNull($broke['baselineDepletionYear'], 'this household is meant to run short even together');

        $worker = $this->worker($broke);
        $this->assertFalse($worker['gapCeilingHit'], 'an already-failing plan is still solvable back to its own baseline');
    }
}
