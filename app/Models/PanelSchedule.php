<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A panel schedule table lifted off a drawing page.
 *
 * Written straight from the engine's AnalysisResult and never edited, so it stays
 * a faithful record of what the drawing said.
 */
class PanelSchedule extends Model
{
    protected $table = 'panel_schedules';

    protected $fillable = [
        'ai_result_id',
        'page',
        'panel_name',
        'rows',
        'raw_headers',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'page' => 'integer',
            'rows' => 'array',
            'raw_headers' => 'array',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<AiResult, $this> */
    public function aiResult(): BelongsTo
    {
        return $this->belongsTo(AiResult::class);
    }
}
