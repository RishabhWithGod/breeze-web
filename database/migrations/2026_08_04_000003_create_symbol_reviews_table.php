<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per detection the AI returned — the unit the reviewer acts on.
 *
 * `ai_*` columns keep what the model said; the un-prefixed columns hold the
 * reviewer's decision. A row is "modified" when the two disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('symbol_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_result_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // The AI's own reference, e.g. crop_0001.
            $table->string('external_id')->nullable();

            $table->string('ai_name');
            $table->string('name');
            $table->unsignedSmallInteger('page')->default(1);
            $table->float('confidence')->default(0);
            $table->json('bbox')->nullable();

            // Which detectors contributed, and how far the run took the crop.
            $table->boolean('source_template')->default(false);
            $table->boolean('source_vector')->default(false);
            $table->boolean('source_vision')->default(false);
            $table->boolean('source_ocr')->default(false);
            $table->json('pipeline')->nullable();
            $table->json('legend')->nullable();
            $table->boolean('is_known')->default(false);

            $table->unsignedInteger('ai_count')->default(1);
            $table->unsignedInteger('final_count')->default(1);

            // pending | approved | rejected
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();

            // Merge/split lineage. A merged row keeps its history but drops out
            // of the final JSON in favour of its target.
            $table->foreignId('merged_into_id')->nullable()->constrained('symbol_reviews')->nullOnDelete();
            $table->foreignId('split_from_id')->nullable()->constrained('symbol_reviews')->nullOnDelete();

            $table->string('crop_path')->nullable();
            $table->string('crop_url')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['ai_result_id', 'status']);
            $table->index(['ai_result_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('symbol_reviews');
    }
};
