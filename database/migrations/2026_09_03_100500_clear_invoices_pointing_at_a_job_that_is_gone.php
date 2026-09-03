<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two invoices name a job that no longer exists.
 *
 * `invoices.job_id` is `SET NULL` on delete, so this cannot have come from a
 * delete through the app — the rows predate that constraint. Cleared rather
 * than deleted: the invoice is real and still names its client, it just is not
 * attached to a job any more. Left alone it also blocks any future change to
 * the table, because MySQL rechecks every constraint when it rebuilds one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('invoices')
            ->whereNotNull('job_id')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('work_jobs')
                ->whereColumn('work_jobs.id', 'invoices.job_id'))
            ->update(['job_id' => null]);

        // Blocked until now by exactly those rows.
        $hasKey = DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            ['invoices', 'invoices_client_id_foreign', 'FOREIGN KEY'],
        );

        if ($hasKey === null && Schema::hasColumn('invoices', 'client_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign('invoices_client_id_foreign');
        });
    }
};
