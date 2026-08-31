<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An approved symbol and its reviewed quantity — one row per distinct name.
 *
 * Regenerated wholesale whenever the final JSON is rebuilt.
 */
class FinalSymbol extends Model
{
    /** Sort keys accepted by `scopeSorted`. */
    public const SORTS = ['count-desc', 'count-asc', 'name-asc', 'name-desc', 'confidence-desc', 'confidence-asc'];

    protected $fillable = [
        'ai_result_id',
        'project_id',
        'name',
        'count',
        'confidence',
        'source_template',
        'source_vector',
        'source_vision',
        'source_ocr',
        'pages',
        'review_ids',
        'was_modified',
        'was_renamed',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'count' => 'integer',
            'confidence' => 'float',
            'source_template' => 'boolean',
            'source_vector' => 'boolean',
            'source_vision' => 'boolean',
            'source_ocr' => 'boolean',
            'pages' => 'array',
            'review_ids' => 'array',
            'was_modified' => 'boolean',
            'was_renamed' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<AiResult, $this> */
    public function aiResult(): BelongsTo
    {
        return $this->belongsTo(AiResult::class);
    }

    public function sourceLabels(): array
    {
        return collect([
            'Template' => $this->source_template,
            'Vector' => $this->source_vector,
            'Vision' => $this->source_vision,
            'OCR' => $this->source_ocr,
        ])->filter()->keys()->all();
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return blank($term) ? $query : $query->where('name', 'like', "%{$term}%");
    }

    /** Narrows to rows a given detector contributed to. */
    public function scopeSource(Builder $query, ?string $source): Builder
    {
        return match ($source) {
            'template', 'vector', 'vision', 'ocr' => $query->where("source_{$source}", true),
            default => $query,
        };
    }

    public function scopeSorted(Builder $query, ?string $sort): Builder
    {
        return match ($sort) {
            'count-asc' => $query->orderBy('count')->orderBy('name'),
            'name-asc' => $query->orderBy('name'),
            'name-desc' => $query->orderByDesc('name'),
            'confidence-desc' => $query->orderByDesc('confidence')->orderBy('name'),
            'confidence-asc' => $query->orderBy('confidence')->orderBy('name'),
            default => $query->orderByDesc('count')->orderBy('name'),
        };
    }
}
