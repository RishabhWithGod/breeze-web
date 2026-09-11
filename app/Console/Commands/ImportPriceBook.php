<?php

namespace App\Console\Commands;

use App\Services\PriceBook\PriceBookImporter;
use Illuminate\Console\Command;
use SplFileInfo;

/**
 * Reads the estimating workbooks into the universal price book — the one an
 * estimate falls back to for a user who has never uploaded a rate list of
 * their own. A per-user book is seeded the same way, through the web upload
 * on the project form; both go through {@see PriceBookImporter}.
 *
 * Running it twice is safe: a workbook is identified by the hash of its
 * contents, and re-importing one replaces its lines rather than doubling them.
 */
class ImportPriceBook extends Command
{
    protected $signature = 'pricebook:import
        {path : A workbook, or a folder of them}
        {--fresh : Remove every previous import first}
        {--dry-run : Read and report, write nothing}';

    protected $description = 'Import estimating workbooks into the universal price book';

    public function __construct(private readonly PriceBookImporter $importer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $files = $this->workbooks($this->argument('path'));

        if ($files === []) {
            $this->error('No .xlsx files found at that path.');

            return self::FAILURE;
        }

        if ($this->option('fresh') && ! $this->option('dry-run')) {
            $this->importer->resetBook(null);
            $this->warn('Previous imports removed.');
        }

        $totalLines = 0;

        foreach ($files as $file) {
            ['lines' => $lines, 'recap' => $recap] = $this->importer->parse($file);
            $totalLines += count($lines);

            $this->line(sprintf(
                '%-50s %4d lines   %s',
                mb_strimwidth($file->getFilename(), 0, 48, '…'),
                count($lines),
                $recap['project_name'] ?? '',
            ));

            if ($this->option('dry-run')) {
                continue;
            }

            $this->importer->store($file, $file->getFilename(), $lines, $recap, null);
        }

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$totalLines} priced lines across ".count($files).' workbook(s). Nothing written.');

            return self::SUCCESS;
        }

        $items = $this->importer->rebuildItems(null);

        $this->newLine();
        $this->info(sprintf(
            'Imported %d priced lines from %d workbook(s) → %d price book items.',
            $totalLines,
            count($files),
            $items,
        ));

        return self::SUCCESS;
    }

    /** @return list<SplFileInfo> */
    private function workbooks(string $path): array
    {
        if (is_file($path)) {
            return [new SplFileInfo($path)];
        }

        $found = glob(rtrim($path, '/').'/*.xlsx') ?: [];
        sort($found);

        // Excel's own lock files start with "~$" and are not workbooks.
        return array_values(array_map(
            fn (string $f) => new SplFileInfo($f),
            array_filter($found, fn (string $f) => ! str_starts_with(basename($f), '~$')),
        ));
    }
}
