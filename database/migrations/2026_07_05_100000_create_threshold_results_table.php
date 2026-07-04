<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A computed decision-support lever threshold for a scenario ("how far can we go on this
 * lever before the money stops lasting?"). Mirrors a {@see simulation_runs} row: it is a
 * queued, cancellable long run with live status + progress (nothing runs silently), and it
 * records the seed, path count, lever grid, engine + tax-year-config versions and a frozen
 * assumption snapshot so the result stays reproducible and auditable.
 *
 * It doubles as the persisted result: the swept curve + crossing land in the encrypted
 * `payload` once done. `inputs_hash` keys it to the exact inputs (effective form-state +
 * engine version + lever/metric/target/grid/paths/seed) so re-running identical inputs is a
 * cache hit and a stale figure is never surfaced after an edit — the same input-edit
 * invalidation the runs use is the primary guard, the hash is the belt-and-braces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threshold_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenario_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('lever_key');           // App\DecisionSupport\LeverKey value
            $table->string('metric');              // RetireForecast\...\Sweep\SweepMetric value
            $table->double('target_probability');  // the success bar the crossing answers (e.g. 0.90)
            $table->unsignedInteger('n_paths');
            $table->unsignedBigInteger('seed');
            $table->text('grid');                  // encrypted:array (list<float> lever values swept)
            $table->string('engine_version');
            $table->string('taxyear_config_version');
            $table->text('assumption_snapshot');   // encrypted:array (frozen AssumptionSet DTO)
            $table->string('inputs_hash')->index(); // sha256 of the full input set — the cache key
            $table->string('status')->default('queued');
            $table->unsignedTinyInteger('progress_pct')->default(0);
            $table->text('payload')->nullable();   // encrypted:array (mapped ThresholdOutcome), null until done
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // The cache-hit lookup: the latest done result for a scenario's exact inputs.
            $table->index(['scenario_id', 'inputs_hash', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threshold_results');
    }
};
