<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Someone on the crew register.
 *
 * The table is still `foremen` and so is the model: a foreman is what most of
 * them are, and renaming the column every task points at would rewrite who ran
 * what for no gain. What changed is that the register now records a role — a
 * supervisor is on it too — and which team they are on.
 */
class Foreman extends Model
{
    public const ROLE_SUPERVISOR = 'supervisor';

    public const ROLE_FOREMAN = 'foreman';

    /** What someone can be on a crew. Supervisor first: it is the senior one. */
    public const ROLES = [self::ROLE_SUPERVISOR, self::ROLE_FOREMAN];

    /** Laravel would otherwise pluralise this to "foremans". */
    protected $table = 'foremen';

    protected $fillable = [
        'name',
        'initials',
        'team_id',
        'role',
        'phone',
        'email',
        'licence_number',
        'started_on',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['started_on' => 'date'];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The account this row was synced from, when it was — a web-created
     * crew member has none. Deliberately not in `$fillable`: this is only
     * ever set by `TechnicianController` linking an approved technician into
     * the register, never by the create/edit forms a manager fills in.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** "Supervisor" / "Foreman", as a screen writes it. */
    public function roleLabel(): string
    {
        return ucfirst($this->role ?? self::ROLE_FOREMAN);
    }

    /** @return HasMany<Job, $this> */
    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class);
    }

    /** @return HasMany<JobTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(JobTask::class);
    }
}
