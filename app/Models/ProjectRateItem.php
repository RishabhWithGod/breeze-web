<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The rate an estimate on this project actually quotes for one item, in one
 * unit. Derived from {@see ProjectRateLine} — one row per item per unit, so
 * the same name priced per foot and per each is two rates, not one
 * disagreeing with itself.
 */
class ProjectRateItem extends Model
{
    protected $fillable = [
        'project_id', 'match_key', 'unit', 'description', 'section', 'subsection',
        'unit_material_cost', 'unit_manhours',
        'sample_count', 'min_material_cost', 'max_material_cost',
        'min_manhours', 'max_manhours', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The lines this rate was derived from. */
    public function lines()
    {
        return $this->hasMany(ProjectRateLine::class, 'match_key', 'match_key')
            ->where('unit', $this->unit)
            ->where('project_id', $this->project_id);
    }
}
