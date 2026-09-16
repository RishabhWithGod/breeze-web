<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One vendor rate list workbook, uploaded for one project.
 *
 * Never shared with another project, never pooled into a company-wide book —
 * see the migration's own docblock for why. Kept beside the rates it
 * produced so any figure an estimate quotes can be traced back to the file
 * and the row it came from.
 */
class ProjectRateImport extends Model
{
    protected $fillable = [
        'project_id', 'file_name', 'file_hash', 'project_name',
        'material_tax_pct', 'overhead_pct', 'profit_pct',
        'electrician_rate', 'supervisor_rate', 'unskilled_rate', 'composite_labor_rate',
        'total_manhours', 'material_cost', 'labor_cost', 'material_tax', 'total_cost', 'base_bid_price',
        'line_count', 'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
            'material_cost' => 'decimal:2',
            'labor_cost' => 'decimal:2',
            'material_tax' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'base_bid_price' => 'decimal:2',
            'total_manhours' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<ProjectRateLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ProjectRateLine::class);
    }
}
