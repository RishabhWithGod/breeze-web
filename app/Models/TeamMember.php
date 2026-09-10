<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamMember extends Model
{
    protected $fillable = ['user_id', 'team_id', 'name', 'initials', 'role', 'billable_rate', 'cost_rate'];

    protected function casts(): array
    {
        return [
            'billable_rate' => 'decimal:2',
            'cost_rate' => 'decimal:2',
        ];
    }

    /** @return BelongsToMany<Job, $this> */
    public function jobs(): BelongsToMany
    {
        return $this->belongsToMany(Job::class, 'job_team_member')
            ->withPivot('role_on_job')
            ->withTimestamps();
    }

    /**
     * The account that signs in as this person, when one is linked.
     *
     * Not every crew record has one — a name-only entry that has never logged
     * in has no `User` row to point at.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The crew this technician is on, mirroring `Foreman::team()`. */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<TimeEntry, $this> */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }
}
