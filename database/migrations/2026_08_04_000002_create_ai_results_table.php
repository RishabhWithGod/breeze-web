<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI response, kept verbatim, plus the reviewed response derived from it.
 *
 * `original_payload` is never edited — it is the audit record. `final_payload`
 * is written when the reviewer generates the final JSON, and is the only input
 * the job and estimate are built from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('upload_id')->nullable()->constrained()->nullOnDelete();

            $table->json('original_payload');
            // Path of original_response.json on the artefact disk.
            $table->string('original_path')->nullable();

            $table->json('final_payload')->nullable();
            $table->string('final_path')->nullable();

            $table->string('model_version')->nullable();
            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedInteger('detection_count')->default(0);
            $table->float('overall_confidence')->nullable();

            // Review lifecycle: pending → in-review → finalised.
            $table->string('review_status')->default('pending');
            $table->foreignId('finalised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('finalised_at')->nullable();

            $table->foreignId('work_job_id')->nullable();
            $table->foreignId('estimate_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_results');
    }
};
