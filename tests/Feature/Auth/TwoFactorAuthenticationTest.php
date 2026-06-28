<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Livewire\AccountSecurity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Two-factor enrolment: the account-security page (App\Livewire\AccountSecurity) drives
 * Fortify's own actions, and the challenge fires at login for an enrolled user. The
 * backend is Fortify's; these guard that the UI flow is wired correctly end to end.
 */
class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_security_page_requires_a_confirmed_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.security'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_the_security_page_loads_once_the_password_is_confirmed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('account.security'))
            ->assertOk()
            ->assertSeeLivewire(AccountSecurity::class);
    }

    public function test_a_user_can_enable_and_confirm_two_factor_authentication(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(AccountSecurity::class)
            ->assertSet('showingQrCode', false)
            ->call('enable')
            ->assertSet('showingQrCode', true);

        // confirm => true, so enabling sets a secret but leaves it pending confirmation.
        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);

        $code = (new Google2FA)->getCurrentOtp(decrypt($user->two_factor_secret));

        $component->set('code', $code)
            ->call('confirm')
            ->assertHasNoErrors()
            ->assertSet('showingQrCode', false)
            ->assertSet('showingRecoveryCodes', true);

        $user->refresh();
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());
        $this->assertCount(8, $user->recoveryCodes());
    }

    public function test_an_invalid_code_does_not_confirm_enrolment(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(AccountSecurity::class)
            ->call('enable')
            ->set('code', '000000')
            ->call('confirm')
            ->assertHasErrors('code');

        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_recovery_codes_can_be_regenerated(): void
    {
        $user = User::factory()->create();
        $this->enroll($user);
        $this->actingAs($user);

        $before = $user->fresh()->recoveryCodes();

        Livewire::test(AccountSecurity::class)
            ->call('regenerateRecoveryCodes')
            ->assertSet('showingRecoveryCodes', true);

        $after = $user->fresh()->recoveryCodes();

        $this->assertNotEquals($before, $after);
        $this->assertCount(8, $after);
    }

    public function test_two_factor_authentication_can_be_disabled(): void
    {
        $user = User::factory()->create();
        $this->enroll($user);
        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());

        $this->actingAs($user);

        Livewire::test(AccountSecurity::class)
            ->call('disable')
            ->assertSet('showingQrCode', false)
            ->assertSet('showingRecoveryCodes', false);

        $this->assertNull($user->fresh()->two_factor_secret);
        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_an_enrolled_user_is_challenged_at_login_and_can_complete_it(): void
    {
        $user = User::factory()->create();   // factory password is 'password'
        $this->enroll($user);

        // Valid credentials do not authenticate a 2FA user; they hand off to the challenge.
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();

        $this->get(route('two-factor.login'))
            ->assertOk()
            ->assertSee('Two-factor authentication');

        $code = (new Google2FA)->getCurrentOtp(decrypt($user->fresh()->two_factor_secret));

        $this->post(route('two-factor.login.store'), ['code' => $code])->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_the_password_confirmation_screen_renders(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('password.confirm'))
            ->assertOk()
            ->assertSee('Confirm your password');
    }

    /**
     * Fully enrol a user in 2FA: generate the secret + recovery codes via Fortify's
     * action, then mark it confirmed directly. The confirmation is stamped without
     * spending a TOTP, because Fortify rejects reuse of a code within its window — so a
     * later challenge in the same test can verify a fresh current code.
     */
    private function enroll(User $user): void
    {
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    }
}
