<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MakeUserAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_promotes_a_user_to_admin(): void
    {
        $user = User::factory()->create(['email' => 'rob@example.test', 'is_admin' => false]);

        $this->artisan('user:make-admin', ['email' => 'rob@example.test'])
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->is_admin);
    }

    public function test_it_revokes_admin_access_with_the_revoke_option(): void
    {
        $user = User::factory()->admin()->create(['email' => 'rob@example.test']);

        $this->artisan('user:make-admin', ['email' => 'rob@example.test', '--revoke' => true])
            ->assertSuccessful();

        $this->assertFalse($user->fresh()->is_admin);
    }

    public function test_it_fails_loudly_for_an_unknown_email(): void
    {
        $this->artisan('user:make-admin', ['email' => 'nobody@example.test'])
            ->expectsOutputToContain('No user found')
            ->assertFailed();
    }

    public function test_it_is_a_no_op_when_already_in_the_target_state(): void
    {
        $user = User::factory()->admin()->create(['email' => 'rob@example.test']);

        $this->artisan('user:make-admin', ['email' => 'rob@example.test'])
            ->expectsOutputToContain('already')
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->is_admin);
    }
}
