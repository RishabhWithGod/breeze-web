<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Starting work is as independent per foreman as finishing it already is —
 * one foreman starting theirs (or finishing theirs) was never meant to make
 * another foreman's own "Start Job" disappear just because the job's single
 * shared `work_jobs.status` flipped to `in-progress` underneath them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('job_foreman_completions', 'started_at')) {
            return;
        }

        Schema::table('job_foreman_completions', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('foreman_id');
        });
    }

    public function down(): void
    {
        Schema::table('job_foreman_completions', function (Blueprint $table) {
            $table->dropColumn('started_at');
        });
    }
};
