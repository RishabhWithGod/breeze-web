<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The free-text feed of everything that happened to a time entry.
 *
 * Same shape as `job_activities` — every mutating action funnels through
 * `TimeEntry::recordActivity()` so this can never fall out of step with the row
 * it describes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entry_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('time_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->index();
            $table->string('description');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entry_activities');
    }
};
