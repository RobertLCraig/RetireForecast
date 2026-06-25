<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A scenario is a saved forecast: a household and a housing decision the user entered
 * in the builder. The raw builder form-state is the SINGLE SOURCE OF TRUTH — held in
 * the encrypted `builder_state` payload; the engine's Household + HousingAction DTOs
 * are *derived* from it on demand (no reverse-mapper, so the inputs have one home).
 *
 * The clear structural columns (name, variant, tax year, IHT toggle, status, the
 * assumption-set reference) are a projection of that form-state, refreshed on every
 * save, kept clear for listing and filtering without decrypting.
 *
 * user_id is the owner. A scenario starts life as a `draft` (the in-progress build,
 * one per user) and becomes `ready` on save. Deleting the user or the assumption set
 * cascades / nulls as set below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('assumption_set_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('variant');
            $table->string('base_tax_year');
            $table->boolean('iht_modelled')->default(false);
            $table->string('status')->default('draft');
            $table->text('builder_state'); // encrypted:array — the raw builder form-state (source of truth)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenarios');
    }
};
