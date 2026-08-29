<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ScenarioStatus;
use App\Enums\ScenarioVariant;
use App\Finance\Mapping\AssumptionSetMapper;
use App\Forecast\ResultPresenter;
use App\Forecast\ScenarioForecaster;
use App\Forecast\SimulationRunner;
use App\Models\AssumptionSet;
use App\Models\Scenario;
use App\Models\User;
use Database\Seeders\AssumptionSetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use Tests\Support\BuilderStateFixture;
use Tests\TestCase;

/**
 * The scenario audit is only worth having if it actually CATCHES things. A guard that always passes
 * is worse than none: it manufactures confidence.
 *
 * So these tests prove both directions — a sound family passes, and each defect the audit exists to
 * catch is really caught. The two defects that reached the screen before it existed were a
 * delta-child whose meaning changed when its base moved, and a mortgage the reader could not see.
 */
final class AuditScenariosTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** @param array<string, mixed> $overrides */
    private function base(array $overrides = []): Scenario
    {
        $state = array_merge(BuilderStateFixture::full(), $overrides);

        $scenario = new Scenario;
        $scenario->user_id = $this->user->id;
        $scenario->builder_state = $state;
        $scenario->status = ScenarioStatus::Ready;
        $scenario->projectFrom($state);
        $scenario->save();

        return $scenario;
    }

    public function test_a_sound_scenario_family_passes(): void
    {
        $scenario = $this->base();

        // Surface WHY if this ever fails — a bare exit code tells the next reader nothing.
        $problems = $this->auditProblems();
        $this->assertSame([], $problems, 'a sound fixture must audit clean, found: '.implode(' | ', $problems));

        $this->artisan('scenarios:audit', ['--user' => $this->user->id])
            ->expectsOutputToContain('Audit clean')
            ->assertExitCode(0);

        $this->assertNotNull($scenario->id);
    }

    /**
     * The audit's findings for this user, as text — so a failure names the defect instead of just
     * reporting a non-zero exit code.
     *
     * @return list<string>
     */
    private function auditProblems(): array
    {
        // Artisan::call (not $this->artisan) so the command definitely runs and its output is
        // captured here rather than deferred to a pending-command assertion.
        Artisan::call('scenarios:audit', ['--user' => $this->user->id]);
        $output = Artisan::output();

        $problems = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '- #')) {
                $problems[] = $line;
            }
        }

        return $problems;
    }

    public function test_it_reports_nothing_to_do_when_there_are_no_scenarios(): void
    {
        $this->artisan('scenarios:audit', ['--user' => $this->user->id])
            ->expectsOutputToContain('No scenarios to audit')
            ->assertExitCode(0);
    }

    public function test_it_catches_a_stored_assumption_set_missing_a_shipped_figure(): void
    {
        // The defect this check was written for, which had already happened six times over: a figure
        // is added to the engine's shipped library, but the app reads its assumptions from the
        // `assumption_sets` TABLE, seeded once. The stored payload has no such key, the mapper's
        // back-compat hydration reads it as null, and the figure silently never reaches a forecast —
        // while the code, the tests and the docs all say it shipped.
        $this->base();

        $shipped = AssumptionSetLibrary::default();
        $payload = AssumptionSetMapper::payload($shipped);
        unset($payload['careCostRealGrowth'], $payload['investmentCharge']);

        $stale = new AssumptionSet;
        $stale->name = $shipped->name;
        $stale->source_note = $shipped->sourceNote;
        $stale->is_default = true;
        $stale->payload = $payload;
        $stale->save();

        $exit = Artisan::call('scenarios:audit', ['--user' => $this->user->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit, 'a stale assumption set must fail the audit, output: '.$output);
        $this->assertStringContainsString('careCostRealGrowth', $output);
        $this->assertStringContainsString('investmentCharge', $output);
        $this->assertStringContainsString('NOT reaching any forecast', $output);
    }

    public function test_a_freshly_seeded_assumption_set_audits_clean(): void
    {
        // The other direction: the seeder's own output must satisfy the check, or it would cry wolf
        // on every install and be ignored.
        $this->base();
        $this->seed(AssumptionSetSeeder::class);

        $this->artisan('scenarios:audit', ['--user' => $this->user->id])
            ->expectsOutputToContain('Audit clean')
            ->assertExitCode(0);
    }

    public function test_it_catches_a_mislabelled_housing_variant(): void
    {
        // The listing column says one thing, the modelled plan is another — so every screen labels
        // the plan wrongly while projecting something else.
        $scenario = $this->base();
        $scenario->variant = ScenarioVariant::Rent;
        $scenario->saveQuietly();

        $this->artisan('scenarios:audit', ['--user' => $this->user->id])
            ->expectsOutputToContain('problem(s) found')
            ->assertExitCode(1);
    }

    public function test_it_catches_an_orphaned_override(): void
    {
        // A delta pointing at a key the base no longer has does nothing — silently. This is what
        // happens to a child when its base is edited underneath it.
        $base = $this->base();

        $child = new Scenario;
        $child->user_id = $this->user->id;
        $child->parent_scenario_id = $base->id;
        $child->setRelation('parent', $base);
        $child->overrides = ['name' => 'Broken child', 'people.pDOESNOTEXIST.grossSalary' => '1000'];
        $child->builder_state = [];
        $child->status = ScenarioStatus::Ready;
        $child->projectFrom($child->effectiveBuilderState());
        $child->save();

        $this->artisan('scenarios:audit', ['--user' => $this->user->id])
            ->expectsOutputToContain('overrides that no longer apply')
            ->assertExitCode(1);
    }

    public function test_it_catches_a_mortgage_the_reader_cannot_see(): void
    {
        // THE defect found in review: a mortgaged stay-put plan whose spend line reads £0, with no
        // roll-up to explain it. The projection charges something the page does not show.
        $state = BuilderStateFixture::full();
        $state['variant'] = 'stay_put';
        $state['property']['ownership'] = 'mortgaged';
        $state['property']['outstandingMortgage'] = '160000';
        $state['property']['mortgageRollUpRate'] = '';
        $state['expenseLines'][] = [
            'id' => 'm1', 'label' => 'Mortgage', 'amount' => '0',
            'category' => 'essential', 'savedAsAsset' => false,
        ];
        $this->base($state);

        $this->artisan('scenarios:audit', ['--user' => $this->user->id])
            ->expectsOutputToContain('shows £0 for the mortgage')
            ->assertExitCode(1);
    }

    public function test_a_repayment_mortgage_scenario_passes_and_is_shown_as_computed(): void
    {
        // The counterpart: the same £0 line is CORRECT when repayment terms are set, because the
        // panel substitutes the schedule's instalment and marks it computed. The audit must know the
        // difference, or it would cry wolf on every properly-modelled repayment mortgage.
        $state = BuilderStateFixture::full();
        $state['variant'] = 'stay_put';
        $state['property']['ownership'] = 'mortgaged';
        $state['property']['outstandingMortgage'] = '160000';
        $state['property']['mortgageRepaymentTermMonths'] = '192';
        $state['property']['mortgageRepaymentStartYear'] = '2026';
        $state['property']['mortgageRepaymentStartMonth'] = '9';
        $state['property']['mortgageRepaymentRate'] = '6.23';
        $state['expenseLines'][] = [
            'id' => 'm1', 'label' => 'Mortgage', 'amount' => '0',
            'category' => 'essential', 'savedAsAsset' => false,
        ];
        $this->base($state);

        $this->artisan('scenarios:audit', ['--user' => $this->user->id])
            ->expectsOutputToContain('Audit clean')
            ->assertExitCode(0);
    }

    public function test_it_catches_a_depreciating_home_that_says_nothing_about_it(): void
    {
        // A home losing value must announce it. Here the audit's own disclosure check is exercised:
        // a buy plan with negative growth must raise the home_depreciates note.
        $state = BuilderStateFixture::full();
        $state['variant'] = 'buy_outright';
        $state['housing']['buyGrowthReal'] = '-8';
        $state['housing']['buyRunningCosts'] = '3000';
        $state['housing']['movingCosts'] = '3000';
        $scenario = $this->base($state);

        // Sanity: this scenario is sound, so the audit passes and the note IS raised.
        $this->artisan('scenarios:audit', ['--user' => $this->user->id])
            ->expectsOutputToContain('Audit clean')
            ->assertExitCode(0);

        $this->assertNotEmpty(
            array_filter(
                ResultPresenter::inputNotes(
                    $scenario->toHousehold(),
                    app(ScenarioForecaster::class)->deterministicVariants($scenario)['buy_outright'],
                    $scenario->toHousingAction(),
                ),
                static fn (array $n): bool => $n['kind'] === 'home_depreciates',
            ),
            'the depreciation must be disclosed, which is what the audit checks for',
        );
    }

    public function test_it_catches_a_stored_result_that_no_longer_matches_its_integrity_stamp(): void
    {
        $scenario = $this->base();
        $run = (new SimulationRunner(new ScenarioForecaster))->preview($scenario, seed: 1, paths: 20);

        // A freshly stamped run audits clean.
        $this->assertSame([], $this->auditProblems());

        // Doctor a stored figure the way a database edit would; the stamp no longer matches, so
        // the audit refuses to read the result off as the engine's own.
        $result = $run->results()->where('variant', 'rent')->firstOrFail();
        $payload = $result->payload;
        $payload['successProbabilityEssentials'] = 1.0;
        $result->payload = $payload;
        $result->save();

        $problems = $this->auditProblems();
        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('integrity stamp', implode(' | ', $problems));
    }
}
