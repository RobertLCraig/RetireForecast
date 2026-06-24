<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One execution of a scenario's forecast. Tracks mode, path count and seed (always
 * recorded so any run is reproducible), live status + progress (nothing runs
 * silently), and the engine + tax-year-config versions plus a frozen encrypted copy
 * of the assumption set used, so a stored result stays auditable and reproducible
 * even after the live assumption set is later edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenario_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('mode');
            $table->unsignedInteger('n_paths');
            $table->unsignedBigInteger('seed');
            $table->string('status')->default('queued');
            $table->unsignedTinyInteger('progress_pct')->default(0);
            $table->string('engine_version');
            $table->string('taxyear_config_version');
            $table->text('assumption_snapshot'); // encrypted:array (frozen AssumptionSet DTO)
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulation_runs');
    }
};
