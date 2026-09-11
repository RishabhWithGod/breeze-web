<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The manager a job, estimate or invoice belongs to.
 *
 * Every one of these already names its project and, usually, its client — and
 * both of those already have an owner. This makes that owner a first-class
 * column instead of something every query has to rediscover through a join,
 * and is what every screen listing "my jobs" / "my estimates" should filter
 * on from here on.
 *
 * Backfilled from the project first (authoritative — `projects.user_id` is
 * not nullable), the client second, and — for invoices only — the job third.
 * A handful of hand-made rows predate both links and stay ownerless; nobody's
 * data is reassigned to guess at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_jobs', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        DB::table('work_jobs')
            ->join('projects', 'projects.id', '=', 'work_jobs.project_id')
            ->update(['work_jobs.user_id' => DB::raw('projects.user_id')]);

        DB::table('work_jobs')
            ->join('clients', 'clients.id', '=', 'work_jobs.client_id')
            ->whereNull('work_jobs.user_id')
            ->update(['work_jobs.user_id' => DB::raw('clients.user_id')]);

        Schema::table('estimates', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        DB::table('estimates')
            ->join('projects', 'projects.id', '=', 'estimates.project_id')
            ->update(['estimates.user_id' => DB::raw('projects.user_id')]);

        DB::table('estimates')
            ->join('clients', 'clients.id', '=', 'estimates.client_id')
            ->whereNull('estimates.user_id')
            ->update(['estimates.user_id' => DB::raw('clients.user_id')]);

        // The series was one global "EST-####" count; it becomes one per
        // manager, so the number itself only has to be unique within it.
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->unique(['user_id', 'number']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        DB::table('invoices')
            ->join('projects', 'projects.id', '=', 'invoices.project_id')
            ->update(['invoices.user_id' => DB::raw('projects.user_id')]);

        DB::table('invoices')
            ->join('clients', 'clients.id', '=', 'invoices.client_id')
            ->whereNull('invoices.user_id')
            ->update(['invoices.user_id' => DB::raw('clients.user_id')]);

        DB::table('invoices')
            ->join('work_jobs', 'work_jobs.id', '=', 'invoices.job_id')
            ->whereNull('invoices.user_id')
            ->update(['invoices.user_id' => DB::raw('work_jobs.user_id')]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'number']);
            $table->unique('number');
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('work_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
