<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TeamMember extends Model
{
    protected $fillable = ['name', 'initials', 'role'];

    /** @return BelongsToMany<Job, $this> */
    public function jobs(): BelongsToMany
    {
        return $this->belongsToMany(Job::class, 'job_team_member')
            ->withPivot('role_on_job')
            ->withTimestamps();
    }
}
