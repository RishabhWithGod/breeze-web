<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Foreman extends Model
{
    /** Laravel would otherwise pluralise this to "foremans". */
    protected $table = 'foremen';

    protected $fillable = ['name', 'initials'];

    /** @return HasMany<Job, $this> */
    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class);
    }

    /** @return BelongsToMany<JobTask, $this> */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(JobTask::class, 'job_task_foremen');
    }
}
