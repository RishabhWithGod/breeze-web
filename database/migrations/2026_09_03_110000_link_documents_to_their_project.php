<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A document belongs to a takeoff.
 *
 * Documents were one shared pile for the whole workspace, which is fine until
 * you have several takeoffs running and every one of them shows every other
 * one's paperwork. A document is filed against a project — the thing a drawing
 * is taken off — and read from there.
 *
 * Backfilled from whatever the document is already attached to: its upload, its
 * job, or its estimate. Anything attached to none of those keeps no project and
 * simply does not appear under one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('documents', 'project_id')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->foreignId('project_id')->nullable()->after('estimate_id')
                    ->constrained('projects')->nullOnDelete();
            });
        }

        // Most specific first: the drawing it came from says exactly which
        // takeoff it belongs to. A job or an estimate says so less directly.
        $sources = [
            ['uploads', 'upload_id'],
            ['work_jobs', 'job_id'],
            ['estimates', 'estimate_id'],
        ];

        foreach ($sources as [$table, $column]) {
            DB::table('documents')
                ->join($table, "{$table}.id", '=', "documents.{$column}")
                ->whereNull('documents.project_id')
                ->whereNotNull("{$table}.project_id")
                ->update(['documents.project_id' => DB::raw("{$table}.project_id")]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('documents', 'project_id')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->dropConstrainedForeignId('project_id');
            });
        }
    }
};
