<?php

use App\Http\Controllers\AccountController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Placeholder login endpoint so the `auth` middleware has a named redirect target
 * while Fortify runs headless (views disabled). The Livewire UI phase replaces this
 * with the real login screen.
 */
Route::get('/login', fn () => response('Authentication required.', 401))->name('login');

// GDPR data-subject controls for the signed-in user. Anonymous callers cannot reach
// these; anonymous use of the app writes nothing server-side.
Route::middleware('auth')->group(function () {
    Route::get('/account/export', [AccountController::class, 'export'])->name('account.export');
    Route::delete('/account', [AccountController::class, 'destroy'])->name('account.destroy');
});
