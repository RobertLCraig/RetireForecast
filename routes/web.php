<?php

use App\Http\Controllers\AccountController;
use App\Livewire\Dashboard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Public landing. Signed-in users go straight to their saved forecasts; the auth
// routes themselves (login, register, password reset, logout) are registered by
// Fortify now that config/fortify.php has views enabled.
Route::get('/', function () {
    return Auth::check() ? redirect()->route('dashboard') : view('home');
})->name('home');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');

    // GDPR data-subject controls for the signed-in user. Anonymous callers cannot
    // reach these; anonymous use of the app writes nothing server-side.
    Route::get('/account/export', [AccountController::class, 'export'])->name('account.export');
    Route::delete('/account', [AccountController::class, 'destroy'])->name('account.destroy');
});
