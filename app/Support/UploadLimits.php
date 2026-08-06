<?php

namespace App\Support;

/**
 * The upload size the server will actually accept.
 *
 * `config/takeoff.php` states what the product allows, but PHP's own
 * `upload_max_filesize` / `post_max_size` sit underneath it and silently truncate
 * anything larger — a file over `upload_max_filesize` arrives with zero bytes.
 * Real drawing sets run to tens of megabytes, so the effective limit is what the
 * dropzone advertises and what validation enforces.
 */
class UploadLimits
{
    /** Megabytes the product allows, before PHP's own ceiling. */
    public static function configuredMb(): int
    {
        return (int) config('takeoff.uploads.max_file_size_mb');
    }

    /** The lower of the configured limit and what PHP will accept. */
    public static function effectiveMb(): int
    {
        return (int) max(1, min(self::configuredMb(), self::phpMb()));
    }

    /** PHP's ceiling: the smaller of per-file and whole-request limits. */
    public static function phpMb(): int
    {
        $perFile = self::iniMb('upload_max_filesize');
        $perRequest = self::iniMb('post_max_size');

        $limits = array_filter([$perFile, $perRequest]);

        return $limits === [] ? self::configuredMb() : (int) min($limits);
    }

    /** True when PHP is the binding constraint, so the UI can say so. */
    public static function isConstrainedByPhp(): bool
    {
        return self::phpMb() < self::configuredMb();
    }

    /** How to lift it, shown alongside the limit. */
    public static function phpHint(): ?string
    {
        if (! self::isConstrainedByPhp()) {
            return null;
        }

        return sprintf(
            'PHP limits uploads to %d MB (upload_max_filesize / post_max_size in %s). '
            .'Raise both to accept larger drawing sets.',
            self::phpMb(),
            php_ini_loaded_file() ?: 'php.ini',
        );
    }

    /** "2M", "8M", "1G" → megabytes. 0 or -1 means unlimited. */
    private static function iniMb(string $key): ?int
    {
        $raw = trim((string) ini_get($key));

        if ($raw === '' || $raw === '-1' || $raw === '0') {
            return null;
        }

        $unit = strtolower(substr($raw, -1));
        $value = (float) $raw;

        $bytes = match ($unit) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };

        return (int) max(1, floor($bytes / 1024 ** 2));
    }
}
