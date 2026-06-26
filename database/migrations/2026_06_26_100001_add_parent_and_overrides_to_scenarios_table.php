<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C2 — delta-child what-ifs. A child scenario references its base via
 * `parent_scenario_id` and stores ONLY its overrides (a sparse delta of the base's
 * form-state), encrypted, in `overrides`. It is not a full copy: the base stays the
 * single source of truth and the child's effective inputs are base ⊕ overrides,
 * resolved by App\Forecast\BuilderStateDelta. A child cannot outlive its base, so
 * deleting the base cascades to its children.
 *
 * Roots leave `parent_scenario_id` null and keep the full form-state in
 * `builder_state` as before; their `overrides` stays null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenarios', function (Blueprint $table) {
            $table->foreignId('parent_scenario_id')
                ->nullable()
                ->after('user_id')
                ->constrained('scenarios')
                ->cascadeOnDelete();
            $table->text('overrides')->nullable()->after('builder_state'); // encrypted:array — child delta only
        });
    }

    public function down(): void
    {
        Schema::table('scenarios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_scenario_id');
            $table->dropColumn('overrides');
        });
    }
};
