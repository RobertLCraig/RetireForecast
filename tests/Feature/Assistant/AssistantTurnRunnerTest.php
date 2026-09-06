<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Assistant\AssistantTurnRunner;
use App\Enums\SimulationStatus;
use App\Models\AssistantTurn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use InvalidArgumentException;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * The third of the queued runners, held to the same rule as the simulation and threshold ones:
 * the turn's `error` column is a status line for the polling panel, not a diagnosis. A turn that
 * dies on the worker has to reach the exception handler as well, or the only evidence left is one
 * sentence with no class, no file and no stack trace.
 */
final class AssistantTurnRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_turn_that_throws_is_reported_not_only_summarised_on_the_row(): void
    {
        Exceptions::fake();

        $user = User::factory()->create();
        $scenario = ScenarioFixture::rich($user);
        $turn = AssistantTurn::create([
            'user_id' => $user->id,
            'scenario_id' => $scenario->id,
            'compare' => false,
            'status' => SimulationStatus::Queued,
            'question' => 'Will the money last?',
        ]);

        // A tax year the registry has no configuration for: the context build throws on it.
        $scenario->update(['base_tax_year' => '1899-00']);

        (new AssistantTurnRunner)->execute($turn->fresh());

        $this->assertSame(SimulationStatus::Failed, $turn->fresh()->status);
        $this->assertNotEmpty($turn->fresh()->error);
        Exceptions::assertReported(InvalidArgumentException::class);
    }
}
