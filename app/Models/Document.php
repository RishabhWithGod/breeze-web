<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Document extends Model
{
    use SoftDeletes;

    public const TYPES = [
        'Blueprint',
        'Electrical Drawing',
        'Specification',
        'Estimate',
        'Invoice',
        'Contract',
        'Change Order',
        'Schedule',
        'Report',
        'Photo',
        'Other',
    ];

    public const VISIBILITY_TEAM = 'team';

    public const VISIBILITY_PRIVATE = 'private';

    protected $fillable = [
        'name',
        'original_filename',
        'storage_path',
        'mime_type',
        'extension',
        'file_size',
        'document_type',
        'job_id',
        'estimate_id',
        // The takeoff this paperwork belongs to — see `scopeForProject`.
        'project_id',
        'folder_id',
        'upload_id',
        'uploaded_by',
        'version_root_id',
        'version',
        'is_latest',
        'is_archived',
        'archived_at',
        'visibility',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'version' => 'integer',
            'is_latest' => 'boolean',
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Job, $this> */
    /** The takeoff this document is filed under. */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** @return BelongsTo<Estimate, $this> */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /** @return BelongsTo<DocumentFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }

    /** The AI Takeoff drawing this document represents, when it is one — never a second copy of the bytes. */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasMany<DocumentActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(DocumentActivity::class)->latest();
    }

    /** @return HasMany<DocumentShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(DocumentShare::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'document_favorites')->withTimestamps();
    }

    /** @return BelongsToMany<User, $this> */
    public function sharedWithUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'document_shares', 'document_id', 'shared_with_user_id')
            ->withPivot('permission')
            ->withTimestamps();
    }

    /** The id every row in this version family is grouped by: the original upload's id. */
    public function familyRootId(): int
    {
        return $this->version_root_id ?? $this->id;
    }

    /** Every version of this document, newest first. */
    public function versionFamily(): Builder
    {
        $rootId = $this->familyRootId();

        return static::withTrashed()
            ->where(fn (Builder $query) => $query->where('id', $rootId)->orWhere('version_root_id', $rootId))
            ->orderByDesc('version');
    }

    public function isFavoritedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->favoritedBy()->where('user_id', $user->id)->exists();
    }

    public function isSharedWith(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->shares()->where('shared_with_user_id', $user->id)->exists();
    }

    public function recordActivity(string $type, string $description, array $meta = []): DocumentActivity
    {
        return $this->activities()->create([
            'user_id' => Auth::id(),
            'type' => $type,
            'description' => $description,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    /** "v3.0 (Latest)" / "v2.0" — the label shown in the Version column. */
    public function versionLabel(): string
    {
        $label = "v{$this->version}.0";

        return $this->is_latest ? "{$label} (Latest)" : $label;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($term) {
            $query->where('name', 'like', "%{$term}%")
                ->orWhere('original_filename', 'like', "%{$term}%")
                ->orWhere('document_type', 'like', "%{$term}%")
                ->orWhereHas('job', fn (Builder $q) => $q->where('name', 'like', "%{$term}%"));
        });
    }

    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        if (blank($type) || $type === 'all') {
            return $query;
        }

        return $query->where('document_type', $type);
    }

    public function scopeForJob(Builder $query, null|int|string $jobId): Builder
    {
        if (blank($jobId) || $jobId === 'all') {
            return $query;
        }

        return $query->where('job_id', (int) $jobId);
    }

    /**
     * Narrowed to one takeoff's paperwork.
     *
     * Documents are filed against the project a drawing is taken off, so this
     * is what makes each takeoff's document section its own rather than a
     * shared pile every takeoff shows.
     */
    public function scopeForProject(Builder $query, null|int|string $projectId): Builder
    {
        return $query->when($projectId, fn (Builder $inner) => $inner->where('project_id', $projectId));
    }

    public function scopeVersionStatus(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            'latest' => $query->where('is_latest', true),
            'superseded' => $query->where('is_latest', false),
            default => $query,
        };
    }

    public function scopeModifiedSince(Builder $query, ?string $range): Builder
    {
        return match ($range) {
            'today' => $query->whereDate('updated_at', today()),
            '7' => $query->where('updated_at', '>=', now()->subDays(7)),
            '30' => $query->where('updated_at', '>=', now()->subDays(30)),
            '90' => $query->where('updated_at', '>=', now()->subDays(90)),
            default => $query,
        };
    }

    /** A private document is visible only to its uploader and the roles that manage all documents. */
    public function scopeVisibleTo(Builder $query, User $user, bool $canManageAll): Builder
    {
        if ($canManageAll) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user) {
            $query->where('visibility', self::VISIBILITY_TEAM)
                ->orWhere('uploaded_by', $user->id)
                ->orWhereHas('shares', fn (Builder $q) => $q->where('shared_with_user_id', $user->id));
        });
    }
}
