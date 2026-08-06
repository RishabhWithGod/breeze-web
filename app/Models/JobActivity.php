<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobActivity extends Model
{
    protected $fillable = ['job_id', 'user_id', 'type', 'description', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
