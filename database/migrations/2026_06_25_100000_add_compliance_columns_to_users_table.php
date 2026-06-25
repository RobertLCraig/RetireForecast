<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compliance columns on the user (step 4):
 *  - disclaimer_acknowledged_at: when the user accepted the first-run guidance-only
 *    disclaimer. Null = not yet acknowledged, which gates the forecast pages.
 *  - can_interpret: admin-granted, off by default. Unlocks the walled-off, advice-style
 *    "interpretation" readouts. Never self-serve; set only from the admin panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('disclaimer_acknowledged_at')->nullable()->after('email_verified_at');
            $table->boolean('can_interpret')->default(false)->after('disclaimer_acknowledged_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['disclaimer_acknowledged_at', 'can_interpret']);
        });
    }
};
