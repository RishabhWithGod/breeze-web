<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copied from the upload that produced this result (`TakeoffOrchestrator::ingest()`)
 * so `EstimateBuilder::open()` can raise an addendum estimate instead of a
 * standalone one, without either of them needing to know about the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_results', function (Blueprint $table) {
            $table->foreignId('addendum_for_estimate_id')->nullable()->after('project_id')
                ->constrained('estimates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('addendum_for_estimate_id');
        });
    }
};
