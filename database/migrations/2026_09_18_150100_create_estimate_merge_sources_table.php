<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which estimates a `kind = merged` estimate was built from — the original
 * plus whichever addenda a user selected when raising a job. `parent_estimate_id`
 * on `estimates` can't express this alone, since a merge usually has more than
 * one source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_merge_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merged_estimate_id')->constrained('estimates')->cascadeOnDelete();
            $table->foreignId('source_estimate_id')->constrained('estimates')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['merged_estimate_id', 'source_estimate_id'], 'estimate_merge_sources_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_merge_sources');
    }
};
