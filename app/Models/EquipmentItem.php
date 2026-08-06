<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of an equipment schedule.
 *
 * Written straight from the engine's AnalysisResult and never edited, so it stays
 * a faithful record of what the drawing said.
 */
class EquipmentItem extends Model
{
    protected $table = 'equipment_items';

    protected $fillable = [
        'ai_result_id',
        'page',
        'tag',
        'description',
        'rating',
        'quantity',
        'extra',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'page' => 'integer',
            'quantity' => 'integer',
            'extra' => 'array',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<AiResult, $this> */
    public function aiResult(): BelongsTo
    {
        return $this->belongsTo(AiResult::class);
    }
}
