<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The assistant's work queue (Phase 3) — the ONE thing the local model may write: an append-only,
 * attributed record of "we should look at X" ideas a reader captured while reading a forecast. It is
 * NOT a curated doc (never PLAN/DECISIONS/HANDOVER) and the model never builds from it; a human
 * promotes an item into the real backlog and deletes it here. Ideas are about the TOOL, not the
 * household's finances, so nothing here is encrypted; a deleted user takes their items with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_backlog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind');              // App\Enums\BacklogItemKind (research|feature|task)
            $table->string('title');
            $table->text('note')->nullable();    // the model's tidied detail
            $table->text('source')->nullable();  // the reader's original words, kept verbatim
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_backlog_items');
    }
};
