<?php

namespace App\Models;

use App\Events\AiTakeoffStatusChanged;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A single submission to the AI service.
 *
 * The processing screen reads its status from here, never from the AI service
 * directly, so the browser only ever talks to Laravel.
 */
class AiJob extends Model
{
    /**
     * Broadcasts on every status transition, from whichever of this model's
     * several writers (`TakeoffOrchestrator`, `markProgress()`, `markFailed()`)
     * caused it — a single hook here means a future writer gets realtime
     * status for free, rather than every call site having to remember to
     * dispatch the event itself.
     */
    protected static function booted(): void
    {
        static::created(function (self $aiJob) {
            event(new AiTakeoffStatusChanged($aiJob));
        });

        static::updated(function (self $aiJob) {
            if ($aiJob->wasChanged('status')) {
                event(new AiTakeoffStatusChanged($aiJob));
            }
        });
    }

    public const STATUS_QUEUED = 'queued';

    public const STATUS_UPLOADING = 'uploading';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_UPLOADING,
        self::STATUS_PROCESSING,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'project_id',
        'upload_id',
        'user_id',
        'external_id',
        'status',
        'progress',
        'stage',
        'stage_label',
        'poll_attempts',
        'error_message',
        'request_meta',
        'last_status_payload',
        'timings',
        'queued_at',
        'submitted_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'progress' => 'integer',
            'poll_attempts' => 'integer',
            'request_meta' => 'array',
            'last_status_payload' => 'array',
            'timings' => 'array',
            'queued_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Upload, $this> */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasOne<AiResult, $this> */
    public function result(): HasOne
    {
        return $this->hasOne(AiResult::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }

    /** Records progress reported by the service (poll response or webhook). */
    public function markProgress(string $status, int $progress, ?string $stage, array $payload = []): void
    {
        $this->update([
            'status' => $status,
            'progress' => max($this->progress, min($progress, 100)),
            'stage' => $stage,
            'stage_label' => $stage ? str($stage)->replace(['_', '-'], ' ')->title()->value() : null,
            'last_status_payload' => $payload === [] ? $this->last_status_payload : $payload,
        ]);
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }
}
