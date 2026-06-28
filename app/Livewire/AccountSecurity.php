<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The signed-in user's two-factor authentication enrolment: turn 2FA on (scan the QR
 * code or type the setup key), confirm it with a code from an authenticator app, view
 * and regenerate the one-time recovery codes, and turn it off.
 *
 * Fortify owns the backend (config/fortify.php enables the feature with confirm +
 * confirmPassword). This drives Fortify's own actions directly rather than its HTTP
 * endpoints so enrolment is one fluid page. The page route is behind the
 * `password.confirm` middleware, which is the equivalent of Fortify's per-endpoint
 * password confirmation for this direct-action approach (a "sudo" step before the
 * secured area).
 *
 * Full-page Livewire components render into the app's Blade layout component.
 */
#[Layout('components.layouts.app')]
class AccountSecurity extends Component
{
    /** Showing the QR code + setup key after enabling, before the code is confirmed. */
    public bool $showingQrCode = false;

    /** Showing the recovery codes (after a successful confirm, or on request). */
    public bool $showingRecoveryCodes = false;

    /** The six-digit code from the authenticator app, used to confirm enrolment. */
    public string $code = '';

    /** A user returning mid-enrolment (secret set but unconfirmed) lands back on the QR step. */
    public function mount(): void
    {
        $user = auth()->user();

        $this->showingQrCode = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;
    }

    public function enable(EnableTwoFactorAuthentication $enable): void
    {
        $enable(auth()->user());

        $this->showingQrCode = true;
        $this->showingRecoveryCodes = false;
        $this->code = '';
    }

    public function confirm(ConfirmTwoFactorAuthentication $confirm): void
    {
        // Throws a ValidationException keyed `code` on a wrong/expired code, which
        // Livewire surfaces on the field; only a valid code stamps two_factor_confirmed_at.
        $confirm(auth()->user(), $this->code);

        $this->showingQrCode = false;
        $this->showingRecoveryCodes = true;
        $this->code = '';
    }

    public function showRecoveryCodes(): void
    {
        $this->showingRecoveryCodes = true;
    }

    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generate): void
    {
        $generate(auth()->user());

        $this->showingRecoveryCodes = true;
    }

    public function disable(DisableTwoFactorAuthentication $disable): void
    {
        $disable(auth()->user());

        $this->reset('showingQrCode', 'showingRecoveryCodes', 'code');
    }

    public function render(): View
    {
        $user = auth()->user()->fresh();

        $hasSecret = $user->two_factor_secret !== null;
        $confirmed = $user->two_factor_confirmed_at !== null;

        return view('livewire.account-security', [
            'enabled' => $confirmed,
            'pending' => $hasSecret && ! $confirmed,
            'qrSvg' => $this->showingQrCode && $hasSecret ? $user->twoFactorQrCodeSvg() : null,
            'setupKey' => $this->showingQrCode && $hasSecret ? decrypt($user->two_factor_secret) : null,
            'recoveryCodes' => $this->showingRecoveryCodes && $hasSecret ? $user->recoveryCodes() : [],
        ])->title('Security');
    }
}
