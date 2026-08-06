<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Upload extends Model
{
    protected $fillable = [
        'user_id',
        'project_id',
        'name',
        'format',
        'page_count',
        'size_bytes',
        'path',
        'thumbnail_path',
        'preview_paths',
        'annotated_path',
        'final_path',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'page_count' => 'integer',
            'preview_paths' => 'array',
        ];
    }

    /** Maps a file extension onto the badge shown on the upload tile. */
    public static function formatFor(string $fileName): string
    {
        return match (Str::lower(pathinfo($fileName, PATHINFO_EXTENSION))) {
            'pdf' => 'PDF',
            'dwg' => 'DWG',
            'dxf' => 'CAD',
            'bim', 'ifc', 'rvt' => 'BIM',
            default => 'CAD',
        };
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<AiJob, $this> */
    public function aiJobs(): HasMany
    {
        return $this->hasMany(AiJob::class);
    }

    /** Preview of the given 1-indexed page, when one was rendered. */
    public function previewFor(int $page): ?string
    {
        return ($this->preview_paths ?? [])[$page - 1] ?? null;
    }
}
