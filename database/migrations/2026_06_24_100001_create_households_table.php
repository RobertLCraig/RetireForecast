<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A saved household: the people and everything they own and spend. Per the
 * persistence decision, all of that sensitive detail lives in one encrypted JSON
 * payload; only name and region stay in the clear as structural columns for
 * listing. user_id is the owner (nullable only to honour the documented shape;
 * anonymous use never writes a row, so a saved household always has an owner).
 * Deleting the user hard-deletes their households (GDPR).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('region');
            $table->text('payload'); // encrypted:array (Household DTO, minus name/region)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('households');
    }
};
