<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMetric extends Model
{
    protected $fillable = [
        'project_id',
        'label',
        'value',
        'delta',
        'trend',
        'hint',
        'position',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
