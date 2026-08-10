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

    /** Same taxonomy as a job, so a converted project keeps its type. */
    public const TYPES = ['residential', 'commercial', 'industrial'];

    /** Offered by the Create Project form; `discipline` itself is free text. */
    public const DISCIPLINES = [
        'Electrical',
        'Mechanical',
        'Plumbing',
        'Fire Protection',
        'Low Voltage',
        'Structural',
    ];

    /** How the Projects list can be ordered. */
    public const SORTS = ['recent', 'oldest', 'name-asc', 'due-asc', 'documents-desc'];

    protected $fillable = [
        'user_id',
        'name',
        'code',
        'client',
        'location',
        'drawing_name',
        'discipline',
        'project_type',
        'status',
        'review_status',
        'items_count',
        'page_count',
        'overall_confidence',
        'notes',
        'started_at',
        'due_date',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'items_count' => 'integer',
            'page_count' => 'integer',
            'overall_confidence' => 'float',
            'started_at' => 'datetime',
            'due_date' => 'date',
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

    /** Matches a project name, its client, its number or its site. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $query) => $query
            ->where('name', 'like', "%{$term}%")
            ->orWhere('client', 'like', "%{$term}%")
            ->orWhere('code', 'like', "%{$term}%")
            ->orWhere('location', 'like', "%{$term}%"));
    }

    /**
     * Ordering for the Projects list.
     *
     * `due-asc` puts dated projects first — a project with no due date is not
     * "due soonest", so it sorts to the back rather than to the front.
     */
    public function scopeSorted(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'oldest' => $query->oldest(),
            'name-asc' => $query->orderBy('name'),
            'due-asc' => $query->orderByRaw('due_date is null')->orderBy('due_date'),
            'documents-desc' => $query->orderByDesc('uploads_count'),
            default => $query->latest(),
        };
    }
}
