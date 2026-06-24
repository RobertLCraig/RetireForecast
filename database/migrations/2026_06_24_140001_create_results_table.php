<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The aggregated Monte Carlo outcome for one housing variant of a run. A buy-vs-rent
 * run produces three (stay_put, buy_outright, rent) on identical seeds. The figures
 * (success probabilities, terminal-wealth percentiles, fan-chart bands) are
 * sensitive, so the whole SimulationResult lives in the encrypted payload; the
 * variant stays clear for lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('simulation_run_id')->constrained()->cascadeOnDelete();
            $table->string('variant');
            $table->text('payload'); // encrypted:array (SimulationResult)
            $table->timestamps();

            $table->unique(['simulation_run_id', 'variant']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('results');
    }
};
