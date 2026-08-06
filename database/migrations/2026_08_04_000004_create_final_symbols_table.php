<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reviewed takeoff, aggregated by symbol name — the rows behind the final
 * symbol table and the only quantities the job and estimate may use.
 *
 * Rebuilt from scratch every time the final JSON is generated, so it can never
 * drift from the approved reviews.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('final_symbols', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_result_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->unsignedInteger('count');
            $table->float('confidence')->default(0);

            $table->boolean('source_template')->default(false);
            $table->boolean('source_vector')->default(false);
            $table->boolean('source_vision')->default(false);
            $table->boolean('source_ocr')->default(false);

            $table->json('pages')->nullable();
            $table->json('review_ids')->nullable();
            $table->boolean('was_modified')->default(false);
            $table->boolean('was_renamed')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['ai_result_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_symbols');
    }
};
