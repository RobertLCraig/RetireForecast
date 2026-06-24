<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A scenario is one housing decision (variant) over a household, run under a chosen
 * assumption set and tax year. The housing action's figures are sensitive, so they
 * live in the encrypted payload; variant, tax year, IHT toggle, status and the
 * assumption-set reference are clear structural columns for listing and filtering.
 *
 * user_id is denormalised from the household for straightforward ownership scoping.
 * Deleting the user or the household hard-deletes the scenario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('assumption_set_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('variant');
            $table->string('base_tax_year');
            $table->boolean('iht_modelled')->default(false);
            $table->string('status')->default('draft');
            $table->text('payload'); // encrypted:array (HousingAction DTO)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenarios');
    }
};
