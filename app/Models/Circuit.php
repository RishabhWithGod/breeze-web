<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A circuit read from a panel schedule or a homerun note.
 *
 * Written straight from the engine's AnalysisResult and never edited, so it stays
 * a faithful record of what the drawing said.
 */
class Circuit extends Model
{
    protected $table = 'circuits';

    protected $fillable = [
        'ai_result_id',
        'page',
        'number',
        'description',
        'breaker',
        'panel',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'page' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<AiResult, $this> */
    public function aiResult(): BelongsTo
    {
        return $this->belongsTo(AiResult::class);
    }
}
