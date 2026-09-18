<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scans every table for rows created or updated since a given time, and
 * reports what changed — a quick way to answer "did anything change in the
 * database since yesterday's script?" without hand-writing a query per table.
 *
 * Only tables with a `created_at` and/or `updated_at` column can be checked;
 * a table with neither is skipped (there is no timestamp to compare against)
 * and listed separately so it is clear it was not silently ignored.
 */
class ShowDatabaseChanges extends Command
{
    protected $signature = 'db:changes
        {--since= : Only rows touched at or after this moment (e.g. "2026-09-16 09:00:00"). Defaults to 24 hours ago.}
        {--table= : Only check this one table, by name.}
        {--details : List the actual changed rows (id + timestamps), not just counts.}
        {--limit=50 : Max rows listed per table when --details is on.}';

    protected $description = 'List rows created or updated since a given time, across every table';

    public function handle(): int
    {
        $since = $this->option('since') !== null
            ? Carbon::parse($this->option('since'))
            : now()->subDay();

        $this->info("Checking for changes since {$since->toDateTimeString()} ({$since->diffForHumans()})…");
        $this->newLine();

        $onlyTable = $this->option('table');
        $tables = collect(Schema::getTables())
            ->pluck('name')
            ->unique()
            ->when($onlyTable !== null, fn ($tables) => $tables->filter(fn ($name) => $name === $onlyTable))
            ->reject(fn ($name) => $name === 'migrations')
            ->sort()
            ->values();

        if ($tables->isEmpty()) {
            $this->warn($onlyTable !== null ? "No table named \"{$onlyTable}\"." : 'No tables found.');

            return self::FAILURE;
        }

        $rows = [];
        $skipped = [];
        $totalChanged = 0;

        foreach ($tables as $table) {
            $columns = collect(Schema::getColumns($table))->pluck('name');
            $hasCreatedAt = $columns->contains('created_at');
            $hasUpdatedAt = $columns->contains('updated_at');

            if (! $hasCreatedAt && ! $hasUpdatedAt) {
                $skipped[] = $table;

                continue;
            }

            $query = DB::table($table)->where(function ($query) use ($since, $hasCreatedAt, $hasUpdatedAt) {
                if ($hasCreatedAt) {
                    $query->orWhere('created_at', '>=', $since);
                }
                if ($hasUpdatedAt) {
                    $query->orWhere('updated_at', '>=', $since);
                }
            });

            $new = $hasCreatedAt ? (clone $query)->where('created_at', '>=', $since)->count() : null;
            $changed = $query->count();

            if ($changed === 0) {
                continue;
            }

            $totalChanged += $changed;
            $rows[] = [$table, $new ?? '—', $changed];

            if ($this->option('details')) {
                $this->line("  <fg=cyan>{$table}</> — {$changed} row(s)");

                $detailQuery = DB::table($table)->where(function ($query) use ($since, $hasCreatedAt, $hasUpdatedAt) {
                    if ($hasCreatedAt) {
                        $query->orWhere('created_at', '>=', $since);
                    }
                    if ($hasUpdatedAt) {
                        $query->orWhere('updated_at', '>=', $since);
                    }
                });

                if ($hasUpdatedAt) {
                    $detailQuery->orderByDesc('updated_at');
                } elseif ($hasCreatedAt) {
                    $detailQuery->orderByDesc('created_at');
                }

                $select = array_values(array_filter([
                    $columns->contains('id') ? 'id' : null,
                    $hasCreatedAt ? 'created_at' : null,
                    $hasUpdatedAt ? 'updated_at' : null,
                ]));

                $detailQuery->limit((int) $this->option('limit'))
                    ->get($select !== [] ? $select : ['*'])
                    ->each(function ($row) {
                        $this->line('    '.json_encode($row));
                    });

                $this->newLine();
            }
        }

        if ($rows === []) {
            $this->info('No changes found in that window.');
        } else {
            $this->table(['Table', 'New rows', 'Changed rows (new + updated)'], $rows);
            $this->info("Total changed rows: {$totalChanged}");
        }

        if ($skipped !== []) {
            $this->newLine();
            $this->warn('Skipped (no created_at/updated_at column to check): '.implode(', ', $skipped));
        }

        return self::SUCCESS;
    }
}
