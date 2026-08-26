<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One AI detection, and the reviewer's verdict on it.
 *
 * Only rows that are approved and not merged away reach the final JSON, and
 * they contribute `final_count` — never `ai_count`.
 */
class SymbolReview extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    /** Which part of the engine's response produced this card. */
    public const ORIGIN_SYMBOL = 'symbol';

    public const ORIGIN_NEEDS_REVIEW = 'needs_review';

    /** The engine's own verdict, before a person looks at it. */
    public const CATEGORY_KNOWN = 'known';

    public const CATEGORY_UNKNOWN = 'unknown';

    public const CATEGORY_REJECTED = 'rejected';

    public const CATEGORY_NEEDS_REVIEW = 'needs-review';

    protected $fillable = [
        'ai_result_id',
        'project_id',
        'external_id',
        'origin',
        'ai_category',
        'reason',
        'ai_name',
        'name',
        'page',
        'confidence',
        'bbox',
        'source_template',
        'source_vector',
        'source_vision',
        'source_ocr',
        'pipeline',
        'evidence',
        'legend',
        'is_known',
        'ai_count',
        'final_count',
        'status',
        'notes',
        'merged_into_id',
        'split_from_id',
        'crop_path',
        'crop_url',
        'crop_id',
        'image_id',
        'image_path',
        'crop_count',
        'final_decision',
        'detection_source',
        'stages',
        'reviewed_by',
        'reviewed_at',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'page' => 'integer',
            'confidence' => 'float',
            'bbox' => 'array',
            'pipeline' => 'array',
            'evidence' => 'array',
            'stages' => 'array',
            'legend' => 'array',
            'source_template' => 'boolean',
            'source_vector' => 'boolean',
            'source_vision' => 'boolean',
            'source_ocr' => 'boolean',
            'is_known' => 'boolean',
            'ai_count' => 'integer',
            'final_count' => 'integer',
            'crop_count' => 'integer',
            'position' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiResult, $this> */
    public function aiResult(): BelongsTo
    {
        return $this->belongsTo(AiResult::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<SymbolReview, $this> */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /** @return HasMany<SymbolReview, $this> */
    public function mergedFrom(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_id');
    }

    /** @return HasMany<ApprovalHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(ApprovalHistory::class)->latest('id');
    }

    /** Renamed, recounted or merged away from what the AI reported. */
    public function isModified(): bool
    {
        return $this->name !== $this->ai_name
            || $this->final_count !== $this->ai_count
            || $this->merged_into_id !== null;
    }

    public function isRenamed(): bool
    {
        return $this->name !== $this->ai_name;
    }

    /** Counts towards the final JSON. */
    public function countsTowardsFinal(): bool
    {
        return $this->status === self::STATUS_APPROVED && $this->merged_into_id === null;
    }

    /** Flagged by the engine as needing a human, rather than counted outright. */
    public function needsReview(): bool
    {
        return $this->origin === self::ORIGIN_NEEDS_REVIEW;
    }

    /** Detector names that contributed, in the order the UI lists them. */
    public function sourceLabels(): array
    {
        return collect([
            'Template' => $this->source_template,
            'Vector' => $this->source_vector,
            'Vision' => $this->source_vision,
            'OCR' => $this->source_ocr,
        ])->filter()->keys()->all();
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED => $query->where('status', $status),
            'modified' => $query->where(fn (Builder $inner) => $inner
                ->whereColumn('name', '!=', 'ai_name')
                ->orWhereColumn('final_count', '!=', 'ai_count')
                ->orWhereNotNull('merged_into_id')),
            'known' => $query->where('is_known', true),
            'unknown' => $query->where('is_known', false),
            'needs-review' => $query->where('origin', self::ORIGIN_NEEDS_REVIEW),
            'ai-rejected' => $query->where('ai_category', self::CATEGORY_REJECTED),
            default => $query,
        };
    }

    /**
     * Hides an *approved* card with nothing left to count — a zero
     * `final_count` on an approved row looks like a real, counted item but
     * carries nothing, which is just clutter. A `pending` row stays visible
     * regardless of count, since it hasn't been given one yet and a reviewer
     * still needs to see it to act on it; a `rejected` row stays visible too
     * — zero is the expected, meaningful count for something turned down,
     * not a stray leftover.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->where('status', '!=', self::STATUS_APPROVED)
            ->orWhere('final_count', '>', 0));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $inner) => $inner
            ->where('name', 'like', "%{$term}%")
            ->orWhere('ai_name', 'like', "%{$term}%")
            ->orWhere('external_id', 'like', "%{$term}%"));
    }
}
