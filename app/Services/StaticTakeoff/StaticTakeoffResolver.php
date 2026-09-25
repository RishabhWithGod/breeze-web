<?php

namespace App\Services\StaticTakeoff;

use App\Models\StaticTakeoffDataset;

/**
 * Matches an uploaded drawing to a pre-stored static takeoff dataset by the
 * file's own content hash — stable across renames, immune to path changes.
 */
class StaticTakeoffResolver
{
    public function findByFile(string $absolutePath): ?StaticTakeoffDataset
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        $hash = hash_file('sha256', $absolutePath);

        if ($hash === false) {
            return null;
        }

        return $this->findByHash($hash);
    }

    public function findByHash(string $hash): ?StaticTakeoffDataset
    {
        return StaticTakeoffDataset::query()
            ->where('file_hash', $hash)
            ->where('is_active', true)
            ->first();
    }
}
