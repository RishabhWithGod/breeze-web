<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\JobAttendance;
use App\Models\TimeEntry;
use App\Services\TimeTracking\DailyTimesheetBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The field app's Time Log: one row per session — each GPS check-in cycle and each timer or manual
 * entry — newest first, the signed-in person's own. It is what {@see SessionLogBuilder} lays out for the web, in the
 * shape a phone needs (ISO times, the site, whether the fix was verified, what is flagged).
 */
class TimeLogController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly DailyTimesheetBuilder $filters) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Personal time: the field app's log is always the signed-in person's own. A crew's time is
        // read on the web Time Log.
        $canViewCrew = false;
        $filters = [
            'from' => $data['from'] ?? now()->subDays(30)->toDateString(),
            'to' => $data['to'] ?? now()->toDateString(),
            'search' => $data['search'] ?? null,
        ];
        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? 30);
        $take = $page * $perPage;

        $entries = $this->filters->entryQuery($filters, $canViewCrew, $user->id);
        $attendance = $this->filters->attendanceQuery($filters, $canViewCrew, $user->id)
            ->when(
                filled($filters['search']),
                fn ($q) => $q->where(fn ($w) => $w
                    ->whereHas('job', fn ($j) => $j->where('name', 'like', "%{$filters['search']}%")->orWhere('location', 'like', "%{$filters['search']}%"))
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$filters['search']}%"))),
            );

        $total = $entries->clone()->count() + $attendance->clone()->count();

        $rows = $entries->clone()
            ->with(['job:id,name,location', 'user:id,name,role', 'teamMember:id,name,role', 'jobTask:id,title'])
            ->orderByDesc('date')->orderByDesc('start_time')->orderByDesc('id')
            ->limit($take)->get()
            ->map(fn (TimeEntry $entry) => $this->entryRow($entry, $user->id))
            ->concat(
                $attendance->clone()
                    ->with(['job' => fn ($q) => $q->withTrashed()->select('id', 'name', 'location', 'geofence_radius'), 'user:id,name,role', 'corrections'])
                    ->orderByDesc('date')->orderByDesc('check_in_at')->orderByDesc('id')
                    ->limit($take)->get()
                    ->map(fn (JobAttendance $session) => $this->attendanceRow($session, $user->id))
            )
            ->sortByDesc('sortKey')
            ->slice(($page - 1) * $perPage, $perPage)
            ->map(fn (array $row) => array_diff_key($row, ['sortKey' => true]))
            ->values()
            ->all();

        return $this->ok([
            'sessions' => $rows,
            'summary' => $this->summary($user->id),
            'canViewCrew' => $canViewCrew,
            'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'hasMore' => $total > $page * $perPage],
        ]);
    }

    /** Hours worked today and this week, for the signed-in person alone. @return array<string, float> */
    private function summary(int $userId): array
    {
        $hours = function (Carbon $from, Carbon $to) use ($userId): float {
            $entries = (float) TimeEntry::query()->where('user_id', $userId)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->whereNotIn('status', [TimeEntry::STATUS_LOCKED, TimeEntry::STATUS_REJECTED])->sum('hours');
            $sessions = JobAttendance::query()->where('user_id', $userId)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get()
                ->sum(fn (JobAttendance $a) => $a->workingSeconds()) / 3600;

            return round($entries + $sessions, 2);
        };

        return [
            'todayHours' => $hours(now()->startOfDay(), now()->endOfDay()),
            'weekHours' => $hours(now()->startOfWeek(), now()->endOfWeek()),
        ];
    }

    /** @return array<string, mixed> */
    private function attendanceRow(JobAttendance $session, int $viewerId): array
    {
        $open = $session->isCheckedIn();
        $missing = $open && $session->date->lt(Carbon::today());
        $radius = (float) ($session->job?->geofence_radius ?: 100);
        $distance = $session->check_in_distance_meters !== null ? (float) $session->check_in_distance_meters : null;
        $openReports = $session->corrections->filter->isOpenReport()->count();
        $acked = $session->corrections->contains(fn ($c) => $c->kind === 'ack' && $c->user_id === $session->user_id);

        return [
            'key' => 'attendance-'.$session->id,
            'kind' => 'attendance',
            'attendanceId' => $session->id,
            'entryId' => null,
            'jobId' => $session->job_id,
            'sortKey' => ($session->check_in_at?->format('Y-m-d H:i:s') ?? $session->date->toDateString().' 00:00:00').'|a'.$session->id,
            'date' => $session->date->toDateString(),
            'employee' => ['id' => $session->user_id, 'name' => $session->user?->name ?? 'Unknown', 'role' => $session->user?->role, 'mine' => $session->user_id === $viewerId],
            'job' => $session->job?->name,
            'task' => null,
            'startAt' => $session->check_in_at?->toISOString(),
            'endAt' => $session->check_out_at?->toISOString(),
            'hours' => round($session->workingSeconds() / 3600, 2),
            'open' => $open,
            'source' => match ($session->check_in_method) {
                JobAttendance::METHOD_AUTOMATIC => 'automatic',
                JobAttendance::METHOD_PHOTO => 'photo',
                default => 'manual',
            },
            'locationVerified' => $session->check_in_lat !== null && $distance !== null && $distance <= $radius,
            'site' => $session->job?->location,
            'accuracyMeters' => $session->check_in_accuracy !== null ? (float) $session->check_in_accuracy : null,
            'distanceMeters' => $distance,
            'status' => $missing ? 'missing-checkout' : ($open ? 'on-site' : 'completed'),
            'recordedOffline' => $session->checkInWasOffline() || $session->checkOutWasOffline(),
            'reviewFlag' => $session->review_flag,
            'reviewReason' => $session->review_reason,
            'openReports' => $openReports,
            'acknowledged' => $acked,
            'canReport' => $session->user_id === $viewerId,
            'canEdit' => false,
            'note' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function entryRow(TimeEntry $entry, int $viewerId): array
    {
        $day = $entry->date->toDateString();
        $start = $entry->start_time ? Carbon::parse($day.' '.$entry->start_time) : null;
        $end = $entry->end_time ? Carbon::parse($day.' '.$entry->end_time) : null;

        return [
            'key' => 'entry-'.$entry->id,
            'kind' => 'entry',
            'attendanceId' => null,
            'entryId' => $entry->id,
            'jobId' => $entry->job_id,
            'sortKey' => ($start?->format('Y-m-d H:i:s') ?? $day.' 00:00:00').'|e'.$entry->id,
            'date' => $day,
            'employee' => [
                'id' => $entry->user_id,
                'name' => $entry->teamMember?->name ?? $entry->user?->name ?? 'Unknown',
                'role' => $entry->user?->role ?? $entry->teamMember?->role,
                'mine' => $entry->user_id === $viewerId,
            ],
            'job' => $entry->job?->name,
            'task' => $entry->jobTask?->title ?? $entry->task_label,
            'startAt' => $start?->toISOString(),
            'endAt' => $end?->toISOString(),
            'hours' => round((float) $entry->hours, 2),
            'open' => false,
            'source' => $entry->source === TimeEntry::SOURCE_TIMER ? 'timer' : 'manual',
            'locationVerified' => false,
            'site' => $entry->job?->location,
            'accuracyMeters' => null,
            'distanceMeters' => null,
            'status' => match ($entry->status) {
                TimeEntry::STATUS_APPROVED, TimeEntry::STATUS_LOCKED => 'approved',
                TimeEntry::STATUS_REJECTED => 'rejected',
                TimeEntry::STATUS_SUBMITTED => 'pending',
                default => $entry->end_time !== null ? 'pending' : 'draft',
            },
            'recordedOffline' => false,
            'reviewFlag' => null,
            'reviewReason' => null,
            'openReports' => 0,
            'acknowledged' => false,
            'canReport' => false,
            'canEdit' => $entry->user_id === $viewerId && $entry->isEditable(),
            'note' => $entry->description,
        ];
    }
}
