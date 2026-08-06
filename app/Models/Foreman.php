<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
}
