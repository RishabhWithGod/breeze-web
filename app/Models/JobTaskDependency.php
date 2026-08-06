<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One edge of a schedule's dependency graph.
 *
 * `job_task_id` is the task that waits; `depends_on_id` is the task it waits on.
 * The direction matters and is easy to get backwards, which is why both ends are
 * named rather than numbered.
 */
class JobTaskDependency extends Model
{
    /** The successor cannot start until the predecessor finishes. */
    public const FINISH_TO_START = 'finish_to_start';

    /** The successor cannot start until the predecessor starts. */
    public const START_TO_START = 'start_to_start';

    /** The successor cannot finish until the predecessor finishes. */
    public const FINISH_TO_FINISH = 'finish_to_finish';

    public const TYPES = [
        self::FINISH_TO_START,
        self::START_TO_START,
        self::FINISH_TO_FINISH,
    ];

    protected $fillable = ['job_task_id', 'depends_on_id', 'type', 'lag_days'];

    protected function casts(): array
    {
        return ['lag_days' => 'integer'];
    }

    /** The task that waits. */
    public function task(): BelongsTo
    {
        return $this->belongsTo(JobTask::class, 'job_task_id');
    }

    /** The task it waits on. */
    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(JobTask::class, 'depends_on_id');
    }

    /** "Finish → Start", for the dependencies panel. */
    public function label(): string
    {
        return match ($this->type) {
            self::START_TO_START => 'Start → Start',
            self::FINISH_TO_FINISH => 'Finish → Finish',
            default => 'Finish → Start',
        };
    }
}
