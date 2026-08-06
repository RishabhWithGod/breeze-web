<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A photo, drawing or document filed against a task. */
class JobTaskAttachment extends Model
{
    protected $fillable = [
        'job_task_id', 'uploaded_by', 'name', 'path', 'mime_type', 'size_bytes',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(JobTask::class, 'job_task_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** True for the formats the review screen can show inline. */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }
}
