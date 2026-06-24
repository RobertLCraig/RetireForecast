<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed economic assumption sets the forecast runs against. Not personal
 * data, so the figures sit in a plain JSON column; name / source_note / is_default
 * are clear columns for listing and choosing the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assumption_sets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('source_note');
            $table->boolean('is_default')->default(false);
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assumption_sets');
    }
};
