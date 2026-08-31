<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A conductor size the engine read off the drawing.
 *
 * Written straight from the engine's AnalysisResult and never edited, so it stays
 * a faithful record of what the drawing said.
 */
class WireSize extends Model
{
    protected $table = 'wire_sizes';

    protected $fillable = [
        'ai_result_id',
        'page',
        'size',
        'context',
        'count',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'page' => 'integer',
            'count' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<AiResult, $this> */
    public function aiResult(): BelongsTo
    {
        return $this->belongsTo(AiResult::class);
    }
}
