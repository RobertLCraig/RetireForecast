<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * is_admin gates access to the Filament admin panel (go-live lockdown). Off by
 * default, so a freshly registered user is a normal forecast user with no admin
 * reach. Promote the first admin with `php artisan user:make-admin {email}`; once
 * one admin exists they can toggle others from the panel's Users resource.
 *
 * This is the privilege boundary the advice-style `interpret` grant sits behind:
 * can_interpret is only settable from the admin panel, so admin access must be the
 * tighter gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('can_interpret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
