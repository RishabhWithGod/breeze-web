<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two moments of a job's estimate that Analytics measures against:
 * the baseline taken when the job was raised (what was "estimated"), and the
 * final taken when it was completed (what counts as "actual"). Everything
 * between is the live estimate. See `EstimateScope`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_jobs', function (Blueprint $table) {
            $table->json('estimate_baseline')->nullable();
            $table->json('estimate_final')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_jobs', function (Blueprint $table) {
            $table->dropColumn(['estimate_baseline', 'estimate_final']);
        });
    }
};
