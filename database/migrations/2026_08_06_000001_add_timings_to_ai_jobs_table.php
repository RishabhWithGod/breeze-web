<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-stage timings for a run, so "it got slower" can be answered with the stage
 * that grew rather than a guess.
 *
 * `queued_at` is stamped at dispatch: without it the wait between dispatch and a
 * worker picking the job up is invisible, and that wait is exactly what a missing
 * worker looks like.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->json('timings')->nullable()->after('last_status_payload');
            $table->timestamp('queued_at')->nullable()->after('timings');
        });
    }

    public function down(): void
    {
        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->dropColumn(['timings', 'queued_at']);
        });
    }
};
