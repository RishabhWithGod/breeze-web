<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A pre-stored takeoff response for a known PDF, matched by content hash.
 *
 * `takeoff_payload` is shaped exactly like the AI engine's `AnalysisResult` —
 * see `App\Services\Ai\AiResponseNormaliser` — so it can be handed straight
 * into the existing ingest pipeline unmodified. Part of the isolated,
 * removable static takeoff module (`config('static_takeoff.enabled')`).
 */
class StaticTakeoffDataset extends Model
{
    protected $fillable = [
        'name',
        'file_hash',
        'original_filename',
        'file_size',
        'mime_type',
        'pdf_path',
        'takeoff_payload',
        'metadata',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'takeoff_payload' => 'array',
            'metadata' => 'array',
            'is_active' => 'boolean',
            'file_size' => 'integer',
        ];
    }
}
