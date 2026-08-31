<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A note left on a task by a person, in conversation order. */
class JobTaskComment extends Model
{
    protected $fillable = ['job_task_id', 'user_id', 'body'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(JobTask::class, 'job_task_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
