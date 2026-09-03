<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A job, estimate and invoice each name their client and their project.
 *
 * `project_id` on these tables meant "the client" while the two were one row.
 * The split makes that column right again on its own — the row it points at is
 * now a project — but the client was then only reachable through it, and a
 * record whose project is removed would lose who it was for. So the client is
 * named outright, alongside the `client` snapshot that lists already read.
 */
return new class extends Migration
{
    private const TABLES = ['work_jobs', 'estimates', 'invoices'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'client_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->foreignId('client_id')->nullable()->after('project_id')
                        ->constrained('clients')->nullOnDelete();
                });
            }

            // Read off the project it is already linked to.
            DB::table($table)
                ->join('projects', 'projects.id', '=', "{$table}.project_id")
                ->whereNull("{$table}.client_id")
                ->update(["{$table}.client_id" => DB::raw('projects.client_id')]);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'client_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropConstrainedForeignId('client_id');
                });
            }
        }
    }
};
