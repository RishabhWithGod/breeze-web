<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone staffed onto a job in a named role.
 *
 * Releasing an assignment stamps `released_at` rather than deleting the row, so
 * the table is also the assignment history.
 */
class JobAssignment extends Model
{
    public const ROLE_ESTIMATOR = 'estimator';

    public const ROLE_PROJECT_MANAGER = 'project-manager';

    public const ROLE_FOREMAN = 'foreman';

    public const ROLE_ELECTRICIAN = 'electrician';

    public const ROLE_REVIEWER = 'reviewer';

    public const ROLES = [
        self::ROLE_ESTIMATOR,
        self::ROLE_PROJECT_MANAGER,
        self::ROLE_FOREMAN,
        self::ROLE_ELECTRICIAN,
        self::ROLE_REVIEWER,
    ];

    public const ROLE_LABELS = [
        self::ROLE_ESTIMATOR => 'Estimator',
        self::ROLE_PROJECT_MANAGER => 'Project Manager',
        self::ROLE_FOREMAN => 'Foreman',
        self::ROLE_ELECTRICIAN => 'Electrician',
        self::ROLE_REVIEWER => 'Reviewer',
    ];

    protected $fillable = [
        'job_id',
        'team_member_id',
        'user_id',
        'role',
        'name',
        'notes',
        'assigned_by',
        'assigned_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** @return BelongsTo<TeamMember, $this> */
    public function teamMember(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function isActive(): bool
    {
        return $this->released_at === null;
    }

    public function roleLabel(): string
    {
        return self::ROLE_LABELS[$this->role] ?? str($this->role)->headline()->value();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }
}
