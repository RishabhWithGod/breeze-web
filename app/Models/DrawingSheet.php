<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DrawingSheet extends Model
{
    protected $fillable = [
        'project_id',
        'code',
        'title',
        'page_count',
        'scale',
        'symbol_count',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'page_count' => 'integer',
            'symbol_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
