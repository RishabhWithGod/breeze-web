<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The strict draft → submitted → approved/rejected → locked trail.
 *
 * Same shape as `job_status_changes` — a dedicated row per transition, separate
 * from the free-text activity feed, so "what was the status history" is never a
 * string-parsing exercise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entry_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('time_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entry_status_changes');
    }
};
