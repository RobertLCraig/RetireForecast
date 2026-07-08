<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One queued assistant turn (a question awaiting its local-model answer). The generation now
 * runs on the background worker — a slow model was outliving the web server's gateway timeout
 * and surfacing as a raw 504 (2026-07-08) — so the panel queues a turn and polls it, exactly
 * as a simulation run does. Rows are TRANSIENT, not a transcript: the panel deletes a turn as
 * soon as its answer is read into (browser-local) component state, and Clear deletes any
 * leftovers. Question/history/answer are real household talk, so they are encrypted at rest
 * and cascade away with the scenario or the user (GDPR erase needs no extra step).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_turns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scenario_id')->constrained()->cascadeOnDelete();
            $table->boolean('compare')->default(false);
            $table->string('status')->default('queued');
            $table->text('question');
            $table->text('history')->nullable();
            $table->text('answer')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_turns');
    }
};
