<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per submission to the AI service. Holds the external id, the live
 * status the processing screen reads, and the failure detail when a run dies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('upload_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Identifier the AI service assigned. Null until submit succeeds.
            $table->string('external_id')->nullable()->index();
            $table->string('status')->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('stage')->nullable();
            $table->string('stage_label')->nullable();

            $table->unsignedSmallInteger('poll_attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->json('request_meta')->nullable();
            $table->json('last_status_payload')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_jobs');
    }
};
