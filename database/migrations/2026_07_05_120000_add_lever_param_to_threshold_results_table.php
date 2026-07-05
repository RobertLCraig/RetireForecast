<?php

use App\DecisionSupport\LeverKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-lever parameter for a threshold: which person a per-person lever targets (e.g. the
 * per-person longevity lever's person id). Null for the household-wide levers (buy price,
 * retirement age, essential spend, the survivor-fraction levers), which need no target. Kept
 * as its own column rather than folded into `lever_key` so `lever_key` stays a clean
 * {@see LeverKey} value and the parameter joins the inputs hash cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threshold_results', function (Blueprint $table) {
            $table->string('lever_param')->nullable()->after('lever_key');
        });
    }

    public function down(): void
    {
        Schema::table('threshold_results', function (Blueprint $table) {
            $table->dropColumn('lever_param');
        });
    }
};
