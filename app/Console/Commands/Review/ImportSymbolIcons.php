<?php

namespace App\Console\Commands\Review;

use App\Models\SymbolIcon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Registers a folder of generic electrical-symbol reference icons — image
 * files named like `06_duplex_receptacle_GFCI.png` — so `SymbolIconMatcher`
 * can show one on a review card when the takeoff has no real crop for that
 * symbol.
 *
 * Each file's name (minus any leading `NN_` ordering prefix) becomes both
 * the icon's display name and the keyword set it is matched by; the file
 * itself is copied to `public/symbol-icons/`, since a generic reference icon
 * carries no project data and needs no authentication to serve.
 */
class ImportSymbolIcons extends Command
{
    protected $signature = 'takeoff:import-symbol-icons {directory : Folder of symbol reference images}';

    protected $description = 'Register a folder of generic electrical-symbol reference icons for review-card matching';

    private const EXTENSIONS = ['png', 'jpg', 'jpeg', 'svg', 'webp'];

    /** Digit-word aliases so "3-way" and "three way" both match. */
    private const NUMBER_WORDS = [
        '1' => 'one', '2' => 'two', '3' => 'three', '4' => 'four', '5' => 'five',
    ];

    public function handle(): int
    {
        $directory = rtrim((string) $this->argument('directory'), '/');

        if (! is_dir($directory)) {
            $this->error("Directory not found: {$directory}");

            return self::FAILURE;
        }

        $files = collect(scandir($directory) ?: [])
            ->filter(fn (string $file) => in_array(strtolower((string) pathinfo($file, PATHINFO_EXTENSION)), self::EXTENSIONS, true))
            ->values();

        if ($files->isEmpty()) {
            $this->error('No image files (png/jpg/jpeg/svg/webp) found in that directory.');

            return self::FAILURE;
        }

        $publicDir = public_path('symbol-icons');

        if (! is_dir($publicDir)) {
            mkdir($publicDir, 0755, true);
        }

        $count = 0;

        foreach ($files as $file) {
            $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
            $stem = pathinfo($file, PATHINFO_FILENAME);
            // Drop a leading ordering prefix like "06_" or "12-".
            $stem = preg_replace('/^\d+[_-]/', '', $stem) ?? $stem;

            // A bare `array_filter()` also drops a literal "0" segment (e.g.
            // "3/0 THHN" wire gauge, spelled `3_0_THHN`) since PHP treats the
            // string "0" as falsy — only drop genuinely empty segments.
            $words = array_values(array_filter(preg_split('/[_\-]+/', $stem) ?: [], fn (string $word) => $word !== ''));
            $name = collect($words)
                ->map(fn (string $word) => ctype_upper($word) ? $word : Str::ucfirst(Str::lower($word)))
                ->implode(' ');
            $slug = Str::slug($stem);

            $keywords = collect($words)
                ->map(fn (string $word) => Str::lower($word))
                ->flatMap(fn (string $word) => isset(self::NUMBER_WORDS[$word]) ? [$word, self::NUMBER_WORDS[$word]] : [$word])
                ->unique()
                ->values()
                ->all();
            // The whole phrase too, so a multi-word icon (e.g. "duplex
            // receptacle") only wins over a single-word one (e.g. "duplex")
            // when the full phrase is actually present in the symbol's name.
            $keywords[] = Str::lower(implode(' ', $words));

            $publicPath = "symbol-icons/{$slug}.{$extension}";
            copy("{$directory}/{$file}", public_path($publicPath));

            SymbolIcon::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'keywords' => array_values(array_unique($keywords)), 'path' => $publicPath],
            );

            $count++;
        }

        $this->info("Registered {$count} symbol icon(s).");

        return self::SUCCESS;
    }
}
