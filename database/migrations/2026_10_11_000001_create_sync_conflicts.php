<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A change made in the field that could not be applied because the office changed the same
        // thing meanwhile, and the technician sent it up for a manager to decide.
        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->nullable()->constrained('work_jobs')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('entity_type', 24); // task
            $table->unsignedBigInteger('entity_id');
            $table->string('title');
            // [{key, label, kind, field: {value, at}, office: {value, at}}]
            $table->json('fields');
            $table->string('status', 12)->default('open'); // open | resolved
            $table->string('resolution', 16)->nullable(); // keep_office | apply_field
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['status', 'job_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_conflicts');
    }
};
