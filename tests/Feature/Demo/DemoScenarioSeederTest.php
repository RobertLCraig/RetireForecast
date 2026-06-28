<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Demo\DemoScenario;
use App\Enums\ScenarioStatus;
use App\Forecast\ScenarioForecaster;
use App\Models\Scenario;
use App\Models\User;
use Database\Seeders\DemoScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The demo seeder persists the fictional sample plan in the canonical storage shape
 * (builder_state as the source, the what-if as a sparse override delta), produces a
 * forecast that runs end to end, is idempotent, and is safe for a release: it never
 * provisions a default-credential account in production.
 */
class DemoScenarioSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_a_runnable_demo_base_plan_and_a_what_if_child(): void
    {
        $this->seed(DemoScenarioSeeder::class);

        $user = User::where('email', 'demo@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->disclaimer_acknowledged_at); // usable immediately, no first-run gate
        $this->assertFalse($user->is_admin);

        $base = Scenario::where('user_id', $user->id)->whereNull('parent_scenario_id')->sole();
        $this->assertSame(DemoScenario::BASE_NAME, $base->name);
        $this->assertSame(ScenarioStatus::Ready, $base->status);

        $child = Scenario::where('parent_scenario_id', $base->id)->sole();
        $this->assertSame(DemoScenario::CHILD_NAME, $child->name);
        $this->assertTrue($child->isChild());
        $this->assertNotEmpty($child->overrides);
        $this->assertSame([], $child->builder_state); // a child holds no builder_state of its own

        // A living integration smoke: the seeded plan actually runs through the engine.
        $result = (new ScenarioForecaster)->deterministic($base);
        $this->assertGreaterThan(2026, $result->finalCalendarYear);
        $this->assertTrue($result->terminalTotalWealth->isPositive());
    }

    public function test_the_seeded_what_if_changes_the_forecast(): void
    {
        $this->seed(DemoScenarioSeeder::class);

        $forecaster = new ScenarioForecaster;
        $baseWealth = $forecaster->deterministic(Scenario::whereNull('parent_scenario_id')->sole())->terminalTotalWealth->pence;
        $childWealth = $forecaster->deterministic(Scenario::whereNotNull('parent_scenario_id')->sole())->terminalTotalWealth->pence;

        // Retiring two years earlier (the only delta) leaves less wealth — the override
        // resolves through the base and reaches the engine.
        $this->assertLessThan($baseWealth, $childWealth);
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed(DemoScenarioSeeder::class);
        $this->seed(DemoScenarioSeeder::class);

        $this->assertSame(1, User::where('email', 'demo@example.com')->count());
        $this->assertSame(2, Scenario::count()); // one base + one child, not duplicated
    }

    public function test_it_attaches_to_an_explicit_user_when_demo_user_email_is_set(): void
    {
        $existing = User::factory()->create(['email' => 'rob@example.com']);
        $_ENV['DEMO_USER_EMAIL'] = $_SERVER['DEMO_USER_EMAIL'] = 'rob@example.com';

        try {
            $this->seed(DemoScenarioSeeder::class);
        } finally {
            unset($_ENV['DEMO_USER_EMAIL'], $_SERVER['DEMO_USER_EMAIL']);
        }

        $this->assertSame(2, Scenario::where('user_id', $existing->id)->count());
        $this->assertNull(User::where('email', 'demo@example.com')->first()); // no demo account provisioned
    }

    public function test_it_refuses_to_provision_a_demo_account_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        // Call the seeder directly: routing through artisan db:seed would trip the
        // framework's own production-confirmation prompt first. We are asserting the
        // seeder's own refusal to mint a default-credential account on a release.
        $this->expectException(RuntimeException::class);
        (new DemoScenarioSeeder)->run();

        $this->assertSame(0, User::where('email', 'demo@example.com')->count());
    }
}
