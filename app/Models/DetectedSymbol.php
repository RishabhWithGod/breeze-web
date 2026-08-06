<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetectedSymbol extends Model
{
    protected $fillable = [
        'project_id',
        'code',
        'name',
        'category',
        'count',
        'confidence',
        'unit',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'count' => 'integer',
            'confidence' => 'float',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
