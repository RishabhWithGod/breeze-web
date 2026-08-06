<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use SoftDeletes;

    public const STATUSES = ['completed', 'converted', 'draft', 'failed', 'processing'];

    protected $fillable = [
        'user_id',
        'name',
        'client',
        'drawing_name',
        'discipline',
        'status',
        'review_status',
        'items_count',
        'page_count',
        'overall_confidence',
        'notes',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'items_count' => 'integer',
            'page_count' => 'integer',
            'overall_confidence' => 'float',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<DrawingSheet, $this> */
    public function sheets(): HasMany
    {
        return $this->hasMany(DrawingSheet::class)->orderBy('position');
    }

    /** @return HasMany<DetectedSymbol, $this> */
    public function symbols(): HasMany
    {
        return $this->hasMany(DetectedSymbol::class)->orderBy('position');
    }

    /** @return HasMany<ProjectMetric, $this> */
    public function metrics(): HasMany
    {
        return $this->hasMany(ProjectMetric::class)->orderBy('position');
    }

    /** @return HasMany<ProjectActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(ProjectActivity::class)->latest('occurred_at');
    }

    /** @return HasMany<Upload, $this> */
    public function uploads(): HasMany
    {
        return $this->hasMany(Upload::class);
    }

    /** @return HasMany<AiJob, $this> */
    public function aiJobs(): HasMany
    {
        return $this->hasMany(AiJob::class)->latest('id');
    }

    /** @return HasMany<AiResult, $this> */
    public function aiResults(): HasMany
    {
        return $this->hasMany(AiResult::class)->latest('id');
    }

    /** @return HasOne<AiJob, $this> */
    public function latestAiJob(): HasOne
    {
        return $this->hasOne(AiJob::class)->latestOfMany();
    }

    /** The result under review, or the last one reviewed. */
    public function latestAiResult(): HasOne
    {
        return $this->hasOne(AiResult::class)->latestOfMany();
    }

    /** The drawing the takeoff ran against. */
    public function primaryUpload(): HasOne
    {
        return $this->hasOne(Upload::class)->oldestOfMany();
    }

    /** True once the run has symbols to review. */
    public function hasResults(): bool
    {
        return $this->symbols()->exists();
    }

    /** Matches a project name or its client. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $query) => $query
            ->where('name', 'like', "%{$term}%")
            ->orWhere('client', 'like', "%{$term}%"));
    }
}
