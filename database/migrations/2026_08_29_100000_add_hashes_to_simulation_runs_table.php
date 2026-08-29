<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two hashes on a forecast run, mirroring what `threshold_results` already carries:
 *
 * - `inputs_hash` — the cache key. Everything that changes the answer (the effective
 *   form-state, the frozen assumptions, the engine + tax-year stamps, the compute
 *   parameters), so an unchanged scenario re-uses its run instead of recomputing a
 *   10,000-path Monte Carlo.
 * - `integrity_hash` — the tamper-evident stamp over the run's provenance AND its stored
 *   figures, keyed with the app key so it cannot be re-forged from database access alone.
 *
 * Both are nullable because runs predating this migration have neither: a null
 * `inputs_hash` matches no cache lookup (an old run is never served as a hit) and a null
 * `integrity_hash` is reported as unverifiable rather than as tampering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simulation_runs', function (Blueprint $table) {
            $table->string('inputs_hash')->nullable()->index();
            $table->string('integrity_hash')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('simulation_runs', function (Blueprint $table) {
            $table->dropIndex(['inputs_hash']);
            $table->dropColumn(['inputs_hash', 'integrity_hash']);
        });
    }
};
