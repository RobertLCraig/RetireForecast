<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Diff vs your last run": a small, edit-surviving snapshot of each completed run's
 * headline figures (success probabilities, end wealth, the median run-short year) for the
 * scenario's chosen strategy. Simulation runs are deleted when inputs change, so two runs
 * can't be compared across an edit; this column is NOT deleted, so the results page can
 * show how the latest run moved from the one before — including across an input change.
 *
 * Encrypted (`encrypted:array`): it is derived from the household's financial figures.
 * Only the last two entries are kept (current + previous).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenarios', function (Blueprint $table) {
            $table->text('result_snapshots')->nullable()->after('overrides'); // encrypted:array — last 2 runs' headline figures
        });
    }

    public function down(): void
    {
        Schema::table('scenarios', function (Blueprint $table) {
            $table->dropColumn('result_snapshots');
        });
    }
};
