<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The first-run guidance-only acknowledgement gate: nobody reaches a forecast without
 * first accepting that this is guidance, not advice. Acceptance is recorded against the
 * account (auditable, asked once); GDPR rights are not withheld pending it.
 */
class DisclaimerAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unacknowledged_user_is_redirected_from_the_forecast_pages(): void
    {
        $user = User::factory()->unacknowledged()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('disclaimer.show'));
        $this->actingAs($user)->get(route('scenarios.create'))->assertRedirect(route('disclaimer.show'));
    }

    public function test_the_acknowledgement_screen_states_guidance_not_advice(): void
    {
        $user = User::factory()->unacknowledged()->create();

        $this->actingAs($user)->get(route('disclaimer.show'))
            ->assertOk()
            ->assertSee('Guidance only, not financial advice.')
            ->assertSee('Pension Wise');
    }

    public function test_acknowledging_records_a_timestamp_and_lets_the_user_through(): void
    {
        $user = User::factory()->unacknowledged()->create();

        $this->actingAs($user)->post(route('disclaimer.acknowledge'))->assertRedirect(route('dashboard'));

        $this->assertNotNull($user->fresh()->disclaimer_acknowledged_at);
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
    }

    public function test_an_already_acknowledged_user_reaches_the_dashboard_directly(): void
    {
        $user = User::factory()->create(); // acknowledged by default

        $this->actingAs($user)->get('/dashboard')->assertOk();
        // Visiting the gate when already accepted just forwards on.
        $this->actingAs($user)->get(route('disclaimer.show'))->assertRedirect(route('dashboard'));
    }

    public function test_account_export_is_reachable_before_acknowledging(): void
    {
        // Data-subject rights are not held back pending acceptance of the disclaimer.
        $user = User::factory()->unacknowledged()->create();

        $this->actingAs($user)->get(route('account.export'))->assertOk();
    }
}
