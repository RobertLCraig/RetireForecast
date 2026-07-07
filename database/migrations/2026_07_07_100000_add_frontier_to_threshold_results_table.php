<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The 2-D frontier (decision-support Phase 5) rides on the same table as the 1-D threshold: a
 * frontier is the SAME queued, cancellable, inputs-hash-cached run — it just sweeps `lever_key`
 * at each held value of a second lever. `condition_lever_key` names that held lever and
 * `condition_grid` its held values; both are null for an ordinary 1-D threshold, which is the
 * discriminator the model reads. Reusing the table means the edit-invalidation, the progress
 * machinery and the owner-scoped CSV route all apply to a frontier for free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threshold_results', function (Blueprint $table) {
            $table->string('condition_lever_key')->nullable()->after('lever_param');
            $table->text('condition_grid')->nullable()->after('grid'); // encrypted:array (list<float> held values)
        });
    }

    public function down(): void
    {
        Schema::table('threshold_results', function (Blueprint $table) {
            $table->dropColumn(['condition_lever_key', 'condition_grid']);
        });
    }
};
