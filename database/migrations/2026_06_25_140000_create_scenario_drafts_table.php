<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A scenario_draft holds the scenario builder's raw, possibly-incomplete form state so a
 * forecast in progress survives navigation, an accidental "leave", or a closed tab. It is
 * deliberately NOT a Household DTO (which requires complete, valid data); it is the strings
 * the user has typed so far, encrypted at rest like every other sensitive payload. One
 * active draft per user; it is deleted when the forecast is finally saved or discarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenario_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('payload'); // encrypted:array — the builder form state
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenario_drafts');
    }
};
