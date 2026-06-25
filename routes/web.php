<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\DisclaimerController;
use App\Http\Middleware\EnsureDisclaimerAcknowledged;
use App\Livewire\Dashboard;
use App\Livewire\ScenarioBuilder;
use App\Livewire\ScenarioResults;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Public landing. Signed-in users go straight to their saved forecasts; the auth
// routes themselves (login, register, password reset, logout) are registered by
// Fortify now that config/fortify.php has views enabled.
Route::get('/', function () {
    return Auth::check() ? redirect()->route('dashboard') : view('home');
})->name('home');

Route::middleware('auth')->group(function () {
    // The first-run guidance-only acknowledgement. Reachable before acknowledging (it is
    // the gate itself), as are the GDPR controls below (data-subject rights are not held
    // back pending acceptance).
    Route::get('/welcome', [DisclaimerController::class, 'show'])->name('disclaimer.show');
    Route::post('/welcome', [DisclaimerController::class, 'acknowledge'])->name('disclaimer.acknowledge');

    // GDPR data-subject controls for the signed-in user. Anonymous callers cannot
    // reach these; anonymous use of the app writes nothing server-side.
    Route::get('/account/export', [AccountController::class, 'export'])->name('account.export');
    Route::delete('/account', [AccountController::class, 'destroy'])->name('account.destroy');

    // The forecast pages: gated on having accepted the guidance-only disclaimer, so no
    // one reaches a result without first seeing the framing.
    Route::middleware(EnsureDisclaimerAcknowledged::class)->group(function () {
        Route::get('/dashboard', Dashboard::class)->name('dashboard');
        Route::get('/scenarios/create', ScenarioBuilder::class)->name('scenarios.create');
        Route::get('/scenarios/{scenario}/results', ScenarioResults::class)->name('scenarios.results');
    });
});
