<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Set when a drawing is uploaded from the "Upload Addendum" flow for a
 * specific estimate, rather than a fresh, standalone takeoff. Carried forward
 * onto the `AiResult` this upload produces (see the matching migration on
 * `ai_results`), so `EstimateBuilder` knows to raise an addendum estimate
 * instead of a new standalone one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->foreignId('addendum_for_estimate_id')->nullable()->after('project_id')
                ->constrained('estimates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('addendum_for_estimate_id');
        });
    }
};
