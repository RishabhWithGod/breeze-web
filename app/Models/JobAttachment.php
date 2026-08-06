<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class JobAttachment extends Model
{
    protected $fillable = ['job_id', 'user_id', 'name', 'path', 'size', 'mime'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Removes the stored bytes along with the row. */
    public function deleteWithFile(): void
    {
        Storage::disk('local')->delete($this->path);
        $this->delete();
    }
}
