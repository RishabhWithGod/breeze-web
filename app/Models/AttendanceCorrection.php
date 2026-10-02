<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A technician's note, reported problem or acknowledgement on one attendance record. */
class AttendanceCorrection extends Model
{
    public const NOTE = 'note';

    public const CORRECTION = 'correction';

    public const ACK = 'ack';

    public const KINDS = [self::NOTE, self::CORRECTION, self::ACK];

    protected $fillable = ['job_attendance_id', 'user_id', 'kind', 'message', 'status', 'resolved_by', 'resolved_at', 'resolution_note'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function isOpenReport(): bool
    {
        return $this->kind === self::CORRECTION && $this->status === 'open';
    }

    /** @return BelongsTo<JobAttendance, $this> */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(JobAttendance::class, 'job_attendance_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
