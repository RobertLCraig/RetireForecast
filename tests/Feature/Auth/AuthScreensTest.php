<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The UI phase turns Fortify's headless auth into real screens. These guard that the
 * login/register/reset views render and that the end-to-end auth flow works, and that
 * the app's pages sit behind auth where they should.
 */
class AuthScreensTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_screen_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Log in');
    }

    public function test_the_register_screen_renders(): void
    {
        $this->get('/register')->assertOk()->assertSee('Create an account');
    }

    public function test_the_forgot_password_screen_renders(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee('Forgot your password?');
    }

    public function test_a_user_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_creates_and_authenticates_a_user(): void
    {
        $response = $this->post('/register', [
            'name' => 'Pat Saver',
            'email' => 'pat@example.test',
            'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'pat@example.test']);
    }

    public function test_the_dashboard_requires_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_a_signed_in_user_lands_on_their_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertRedirect('/dashboard');
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Your forecasts');
    }

    public function test_an_empty_dashboard_invites_a_first_forecast(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertSee('You have not built a forecast yet.');
    }
}
