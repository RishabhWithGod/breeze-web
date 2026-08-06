<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Copies the pre-MySQL SQLite database forward, row for row.
 *
 * Primary keys are preserved deliberately: every takeoff artefact on disk is filed
 * under a project id, review rows point at result ids, and the notification and
 * activity trails carry ids in their payloads. Re-keying the data would silently
 * break all of it, so a row that cannot keep its id is a failure, not a warning.
 *
 * Safe to re-run: each table is emptied before it is filled, and the whole copy
 * runs in one transaction that rolls back as a unit.
 */
class ImportSqliteDatabase extends Command
{
    protected $signature = 'takeoff:import-sqlite
        {--from=sqlite_legacy : Connection to read from}
        {--to= : Connection to write to, defaulting to the application default}
        {--chunk=500 : Rows per insert}
        {--pretend : Report what would be copied without writing}';

    protected $description = 'Copy the legacy SQLite database into MySQL, preserving ids';

    /**
     * Framework tables that must not be carried over.
     *
     * `migrations` describes the schema of the database being written, not the one
     * being read. Sessions, cache and queued jobs are all live scratch state whose
     * meaning does not survive the move — copying a queued job would re-run an
     * analysis that already happened.
     *
     * @var list<string>
     */
    private const SKIP = [
        'migrations',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
    ];

    public function handle(): int
    {
        $source = DB::connection($this->option('from'));
        $target = DB::connection($this->option('to') ?: config('database.default'));

        if ($source->getDriverName() === $target->getDriverName()
            && $source->getDatabaseName() === $target->getDatabaseName()) {
            $this->error('Source and target are the same database.');

            return self::FAILURE;
        }

        $tables = $this->tablesToCopy($source, $target);

        if ($tables === []) {
            $this->error('No matching tables found. Has `php artisan migrate` been run on the target?');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Copying %d tables from [%s] to [%s].',
            count($tables),
            $source->getDatabaseName(),
            $target->getDatabaseName(),
        ));

        if ($this->option('pretend')) {
            $this->reportPlan($source, $tables);

            return self::SUCCESS;
        }

        try {
            $copied = $this->copy($source, $target, $tables);
        } catch (Throwable $e) {
            $this->error('Nothing was written. The copy failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return $this->verify($source, $target, $tables, $copied);
    }

    /* ------------------------------------------------------------- internals */

    /**
     * Tables present in both databases, in an order that respects foreign keys.
     *
     * The source's own order is used rather than a hand-kept list, because SQLite
     * creates tables in migration order — which is already dependency order.
     *
     * @return list<string>
     */
    private function tablesToCopy(ConnectionInterface $source, ConnectionInterface $target): array
    {
        $sourceTables = collect($source->select(
            "select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by rootpage"
        ))->pluck('name');

        return $sourceTables
            ->reject(fn (string $table) => in_array($table, self::SKIP, true))
            ->filter(fn (string $table) => Schema::connection($target->getName())->hasTable($table))
            ->values()
            ->all();
    }

    /** @param  list<string>  $tables */
    private function reportPlan(ConnectionInterface $source, array $tables): void
    {
        $this->table(
            ['Table', 'Rows'],
            array_map(fn (string $t) => [$t, $source->table($t)->count()], $tables),
        );
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    private function copy(ConnectionInterface $source, ConnectionInterface $target, array $tables): array
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $copied = [];

        /*
         * Constraints are lifted for the copy, not because the data is unsound but
         * because rows arrive in table order rather than dependency order within a
         * table — a self-referencing parent can precede its child. They are restored
         * in every exit path.
         */
        Schema::connection($target->getName())->withoutForeignKeyConstraints(function () use ($source, $target, $tables, $chunk, &$copied) {
            $target->transaction(function () use ($source, $target, $tables, $chunk, &$copied) {
                foreach ($tables as $table) {
                    $target->table($table)->delete();

                    $total = 0;

                    $source->table($table)->orderBy($this->keyFor($source, $table))->chunk($chunk, function ($rows) use ($target, $table, &$total) {
                        $batch = array_map(fn ($row) => (array) $row, $rows->all());

                        $target->table($table)->insert($batch);
                        $total += count($batch);
                    });

                    $copied[$table] = $total;
                    $this->line(sprintf('  %-28s %6d rows', $table, $total));
                }
            });
        });

        return $copied;
    }

    /**
     * A column to page the source by.
     *
     * Chunking needs a stable order; `id` is right for every table here, and the
     * pivot tables that lack one are paged by their first column instead.
     */
    private function keyFor(ConnectionInterface $source, string $table): string
    {
        return Schema::connection($source->getName())->hasColumn($table, 'id')
            ? 'id'
            : Schema::connection($source->getName())->getColumnListing($table)[0];
    }

    /**
     * @param  list<string>  $tables
     * @param  array<string, int>  $copied
     */
    private function verify(ConnectionInterface $source, ConnectionInterface $target, array $tables, array $copied): int
    {
        $rows = [];
        $mismatched = 0;

        foreach ($tables as $table) {
            $from = $source->table($table)->count();
            $to = $target->table($table)->count();
            $ok = $from === $to;
            $mismatched += $ok ? 0 : 1;

            $rows[] = [$table, $from, $to, $ok ? 'ok' : 'MISMATCH'];
        }

        $this->newLine();
        $this->table(['Table', 'SQLite', 'MySQL', ''], $rows);

        if ($mismatched > 0) {
            $this->error("{$mismatched} table(s) did not match. The legacy database is untouched — investigate before using MySQL.");

            return self::FAILURE;
        }

        $this->info(sprintf('All %d tables match (%d rows copied).', count($tables), array_sum($copied)));

        return self::SUCCESS;
    }
}
