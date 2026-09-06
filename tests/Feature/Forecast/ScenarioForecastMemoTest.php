<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Enums\ScenarioStatus;
use App\Forecast\ScenarioForecaster;
use App\Models\AssumptionSet;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * Turning a stored scenario into a forecast is not cheap: decrypt the base form-state,
 * decrypt the parent's, deep-merge the overrides, assemble the household, then project it.
 * A screen that shows several plans asked for all of that several times per plan, and the
 * affordability screen asks for it again for every what-if child.
 *
 * So the forecaster remembers what it derived, for as long as the scenario it derived it
 * from has not moved. These tests hold both halves of that: an unchanged scenario is not
 * projected twice, and a changed one is never served the old answer.
 *
 * Identity (`assertSame`) is the measurement. Two calls that come back as the SAME object
 * cannot have run the projection twice; equal-but-distinct objects mean it ran again.
 */
class ScenarioForecastMemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_forecaster_is_shared_for_the_length_of_one_request(): void
    {
        $this->assertSame(
            app(ScenarioForecaster::class),
            app(ScenarioForecaster::class),
            'Each collaborator on a screen gets its own forecaster, so nothing one derives is reused by the next.'
        );
    }

    public function test_an_unchanged_scenario_is_not_projected_a_second_time(): void
    {
        $scenario = ScenarioFixture::rich(User::factory()->create());
        $forecaster = new ScenarioForecaster;

        $first = $forecaster->deterministicVariants($scenario);
        $second = $forecaster->deterministicVariants($scenario);

        $this->assertSame(
            $first['stay_put'],
            $second['stay_put'],
            'The same unchanged plan was projected all over again on the second ask.'
        );
    }

    public function test_a_saved_edit_is_projected_again_rather_than_served_stale(): void
    {
        $user = User::factory()->create();
        $scenario = ScenarioFixture::rich($user);
        $forecaster = new ScenarioForecaster;

        $before = $forecaster->deterministicVariants($scenario)['stay_put'];

        $state = ScenarioFixture::richState();
        $state['expenseLines'][0]['amount'] = '250000';
        $scenario->fillFromBuilderState($state);
        $scenario->save();

        // Read the row back the way a later request does: a fresh, clean model for the same id.
        $reloaded = Scenario::findOrFail($scenario->id);
        $after = $forecaster->deterministicVariants($reloaded)['stay_put'];

        $this->assertNotSame($before, $after, 'The edited plan was served the forecast of the plan before the edit.');
        $this->assertNotSame(
            $before->depletionCalendarYear,
            $after->depletionCalendarYear,
            'Spending 250k a year on essentials did not change when the money runs out, so the edit never reached the engine.'
        );
    }

    public function test_a_what_if_child_is_not_served_its_bases_forecast(): void
    {
        $user = User::factory()->create();
        $base = ScenarioFixture::rich($user);
        $child = $this->childOf($base, $user, ['expenseLines' => [
            ['id' => 'ess1', 'label' => 'Essentials', 'amount' => '250000', 'category' => 'essential', 'savedAsAsset' => false],
        ]]);
        $forecaster = new ScenarioForecaster;

        $baseForecast = $forecaster->deterministicVariants($base)['stay_put'];
        $childForecast = $forecaster->deterministicVariants($child)['stay_put'];

        $this->assertNotSame($baseForecast, $childForecast, 'The what-if was handed its base\'s forecast.');
        $this->assertNotSame(
            $baseForecast->depletionCalendarYear,
            $childForecast->depletionCalendarYear,
            'The what-if reports the base plan\'s depletion year, so its own overrides never reached the engine.'
        );
    }

    public function test_an_edit_to_the_base_retires_the_childs_forecast(): void
    {
        $user = User::factory()->create();
        $base = ScenarioFixture::rich($user);
        $child = $this->childOf($base, $user, ['name' => 'What if']);
        $forecaster = new ScenarioForecaster;

        $before = $forecaster->deterministicVariants($child)['stay_put'];

        $state = ScenarioFixture::richState();
        $state['expenseLines'][0]['amount'] = '250000';
        $base->fillFromBuilderState($state);
        $base->save();

        $reloaded = Scenario::findOrFail($child->id);
        $after = $forecaster->deterministicVariants($reloaded)['stay_put'];

        $this->assertNotSame(
            $before->depletionCalendarYear,
            $after->depletionCalendarYear,
            'The base moved under the what-if and the what-if still reports the figures from before it moved.'
        );
    }

    public function test_an_edited_assumption_set_retires_the_forecast(): void
    {
        // The scenario row does not move at all here: an admin edits the figures inside a shared
        // assumption set, and every scenario pointing at it is now projected on different numbers.
        $set = AssumptionSet::fromDto(AssumptionSetLibrary::default());
        $set->save();
        $scenario = ScenarioFixture::rich(User::factory()->create(), ['assumptionSetId' => $set->id]);
        $forecaster = new ScenarioForecaster;

        $before = $forecaster->assumptions($scenario)->inflationMean->basisPoints;

        $payload = $set->payload;
        $payload['inflationMean'] += 100; // +1 percentage point
        $set->payload = $payload;
        $set->save();

        $after = $forecaster->assumptions(Scenario::findOrFail($scenario->id))->inflationMean->basisPoints;

        $this->assertNotSame($before, $after, 'The scenario is still projected on the assumptions from before the set was edited.');
    }

    /** @param  array<string, mixed>  $overrides */
    private function childOf(Scenario $base, User $user, array $overrides): Scenario
    {
        $child = new Scenario;
        $child->user_id = $user->id;
        $child->parent_scenario_id = $base->id;
        $child->overrides = $overrides;
        $child->builder_state = [];
        $child->status = ScenarioStatus::Ready;
        $child->projectFrom($child->effectiveBuilderState());
        $child->save();

        return $child->fresh();
    }
}
