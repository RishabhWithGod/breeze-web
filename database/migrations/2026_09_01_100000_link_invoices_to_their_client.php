<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clients and projects were the same thing said twice, so `projects` is now
 * the client register and every intake form picks from it. Jobs and estimates
 * already carried a `project_id`; invoices did not, so they get one here, and
 * all three are then pointed at the client they already name.
 *
 * `client` stays as the snapshot column — it is what the list, the filters and
 * a sent invoice all read, and renaming a client later must not rewrite an
 * invoice already issued under the old name. The backfill below only matches
 * existing rows to a client of exactly the same name; anything that does not
 * match keeps its snapshot and simply has no link, which is what an invoice
 * raised for a one-off name always was.
 *
 * `down()` drops the new invoices column, but deliberately leaves the
 * `work_jobs`/`estimates` links it filled in: those columns predate this
 * migration, and there is no way to tell a link it added from one that was
 * already there.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded: this shipped to a server that had already been given the
        // column by hand, and the backfill below still has to run there.
        if (! Schema::hasColumn('invoices', 'project_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreignId('project_id')->nullable()->after('estimate_id')
                    ->constrained('projects')->nullOnDelete();
            });
        }

        // Jobs and estimates already had the column; rows raised by hand still
        // have it empty, so the same match fills them in and their edit screens
        // open on a real client rather than an empty select.
        foreach (['invoices', 'work_jobs', 'estimates'] as $table) {
            // `projects.name` first: that is the client's name now. A client
            // register built before the merge may still carry the name under
            // `projects.client` instead, so that is tried for whatever is left.
            $this->linkTo($table, 'name');
            $this->linkTo($table, 'client');
        }
    }

    /**
     * Points a table's `project_id` at the client whose `$column` holds exactly
     * the name that table already records.
     *
     * Only rows with no link yet are touched, and only where the name belongs
     * to exactly one client — an ambiguous name is left unlinked rather than
     * attached to a guess.
     */
    private function linkTo(string $table, string $column): void
    {
        $unambiguous = DB::table('projects')
            ->whereNull('deleted_at')
            ->groupBy($column)
            ->havingRaw('count(*) = 1')
            ->pluck($column);

        if ($unambiguous->isEmpty()) {
            return;
        }

        DB::table($table)
            ->join('projects', 'projects.'.$column, '=', $table.'.client')
            ->whereNull($table.'.project_id')
            ->whereIn('projects.'.$column, $unambiguous)
            ->whereNull('projects.deleted_at')
            ->update([$table.'.project_id' => DB::raw('projects.id')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoices', 'project_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropConstrainedForeignId('project_id');
            });
        }
    }
};
