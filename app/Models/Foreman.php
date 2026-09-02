<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Foreman extends Model
{
    /** Laravel would otherwise pluralise this to "foremans". */
    protected $table = 'foremen';

    protected $fillable = [
        'name',
        'initials',
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
