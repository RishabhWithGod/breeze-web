<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use SoftDeletes;

    public const STATUSES = ['completed', 'converted', 'draft', 'failed', 'processing'];

    /** Same taxonomy as a job, so a converted project keeps its type. */
    public const TYPES = ['residential', 'commercial', 'industrial'];

    /** How the Projects list can be ordered. */
    public const SORTS = ['recent', 'oldest', 'name-asc', 'due-asc', 'documents-desc'];

    protected $fillable = [
        'user_id',
        'client_id',
        'name',
        'code',
        'client',
        'location',
        'latitude',
        'longitude',
        'place_id',
        'drawing_name',
        'selected_upload_id',
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
        'estimate_target_total',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'items_count' => 'integer',
            'page_count' => 'integer',
            'overall_confidence' => 'float',
            'started_at' => 'datetime',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'estimate_target_total' => 'decimal:2',
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

    /**
     * Who this project is for.
     *
     * Not called `client`: `projects.client` is a column holding the client's
     * *name*, and Eloquent reads an attribute before a relation — so
     * `$project->client` is that string, and a relation of the same name would
     * be permanently shadowed. The column stays because search, every list
     * resource and the estimate builder already read it.
     *
     * @return BelongsTo<Client, $this>
     */
    public function clientRecord(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * The sites available to this project — its client's whole book.
     *
     * A project and the job on it are at the same place, so the project does
     * not keep a list of its own. `location` on the project is the client's
     * primary site, snapshotted: a printed sheet must not change when the book
     * is later corrected.
     *
     * @return HasManyThrough<ClientAddress, Client, $this>
     */
    public function addresses(): HasManyThrough
    {
        return $this->hasManyThrough(
            ClientAddress::class,
            Client::class,
            'id',
            'client_id',
            'client_id',
            'id',
        )->orderByDesc('is_primary')->orderBy('position')->orderBy('id');
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

    /** The first drawing on record — the default when nothing is chosen. */
    public function primaryUpload(): HasOne
    {
        return $this->hasOne(Upload::class)->oldestOfMany();
    }

    /** @return BelongsTo<Upload, $this> */
    public function selectedUpload(): BelongsTo
    {
        return $this->belongsTo(Upload::class, 'selected_upload_id');
    }

    /**
     * The drawing a takeoff runs against: the one chosen on the client screen,
     * or the first on record when nothing has been chosen.
     *
     * The fallback is not a nicety — a client with one drawing never chooses,
     * and deleting the chosen one clears the choice, so "nothing chosen" is a
     * normal state rather than an edge case.
     */
    public function takeoffDrawing(): ?Upload
    {
        return $this->selectedUpload ?? $this->primaryUpload;
    }

    /** True once the run has symbols to review. */
    public function hasResults(): bool
    {
        return $this->symbols()->exists();
    }

    /** Matches a project name, its drawing's filename, its client, its number or its site. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $query) => $query
            ->where('name', 'like', "%{$term}%")
            ->orWhere('drawing_name', 'like', "%{$term}%")
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
