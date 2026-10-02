<?php

namespace App\Services\TimeTracking;

use App\Models\JobAttendance;
use App\Models\TimeEntry;
use App\Models\User;
use App\Policies\TimeEntryPolicy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * The time log, one row per session: each GPS check-in cycle and each timer or
 * manual entry, with when it started, when it ended and how it got there.
 *
 * Reads the same two tables, through the same filters, as the day-by-day list
 * ({@see DailyTimesheetBuilder}); it only lays them out by session instead of
 * by day. Nothing about how either is recorded or approved changes.
 *
 * An open check-in from an earlier day is a missing checkout: nobody is on site
 * from yesterday. Those, and rejected entries, are the log's exceptions.
 */
class SessionLogBuilder
{
    private const DATE_TIME = 'm/d/Y g:i A';

    public function __construct(
        private readonly DailyTimesheetBuilder $filters,
        private readonly TimeEntryPolicy $policy,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(
        array $filters,
        bool $canViewCrew,
        int $viewerId,
        int $perPage,
        int $page,
        bool $exceptionsOnly = false,
        ?User $viewer = null,
    ): LengthAwarePaginatorContract {
        $page = max(1, $page);

        $entries = $this->filters->entryQuery($filters, $canViewCrew, $viewerId)
            ->when($exceptionsOnly, fn ($q) => $q->where('status', TimeEntry::STATUS_REJECTED));
        $attendance = $this->filters->attendanceQuery($filters, $canViewCrew, $viewerId)
            ->when($exceptionsOnly, fn ($q) => $this->missingCheckout($q));

        $total = $entries->clone()->count() + $attendance->clone()->count();

        // Each side only ever needs to supply as many rows as the pages up to this
        // one can hold — the merge below picks the newest from the two.
        $take = $page * $perPage;

        $rows = $entries->clone()
            ->with(['job:id,name,user_id', 'user:id,name,role', 'teamMember:id,name,role'])
            ->orderByDesc('date')->orderByDesc('start_time')->orderByDesc('id')
            ->limit($take)->get()
            ->map(fn (TimeEntry $entry) => $this->entryRow($entry, $viewer))
            ->concat(
                $attendance->clone()
                    // Deleted jobs included: a check-in left open on one is the usual case.
                    ->with(['job' => fn ($q) => $q->withTrashed()->select('id', 'name', 'user_id'), 'user:id,name,role'])
                    ->orderByDesc('date')->orderByDesc('check_in_at')->orderByDesc('id')
                    ->limit($take)->get()
                    ->map(fn (JobAttendance $session) => $this->attendanceRow($session, $viewer))
            )
            ->sortByDesc('sortKey')
            ->slice(($page - 1) * $perPage, $perPage)
            ->map(fn (array $row) => array_diff_key($row, ['sortKey' => true]))
            ->values()
            ->all();

        return new LengthAwarePaginator($rows, $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
        ]);
    }

    /**
     * How many sessions need a look — scoped to whoever is asking, never to the
     * other filters, so the count does not shrink as the list is narrowed.
     *
     * @param  array<string, mixed>  $filters
     */
    public function exceptionCount(array $filters, bool $canViewCrew, int $viewerId): int
    {
        $scope = ['job' => $filters['job'] ?? '', 'team_member' => $filters['team_member'] ?? ''];

        return $this->filters->entryQuery($scope, $canViewCrew, $viewerId)
            ->where('status', TimeEntry::STATUS_REJECTED)->count()
            + $this->missingCheckout($this->filters->attendanceQuery($scope, $canViewCrew, $viewerId))->count();
    }

    /** A check-in still open on a day that is over. */
    private function missingCheckout($query)
    {
        return $query
            ->where('status', JobAttendance::STATUS_CHECKED_IN)
            ->whereDate('date', '<', Carbon::today());
    }

    /** @return array<string, mixed> */
    private function attendanceRow(JobAttendance $session, ?User $viewer): array
    {
        $open = $session->isCheckedIn();
        $missing = $open && $session->date->lt(Carbon::today());

        return [
            'key' => 'attendance-'.$session->id,
            'attendanceId' => $session->id,
            'entryId' => null,
            'canApprove' => false,
            'canCheckOut' => $viewer !== null && $this->policy->closeAttendance($viewer, $session),
            // For the checkout form: the earliest it can be, and a sensible start —
            // a normal shift after the check-in, never later than now.
            'checkOutMin' => $session->check_in_at?->format('Y-m-d\TH:i'),
            'checkOutSuggested' => $session->check_in_at === null
                ? null
                : $session->check_in_at->copy()->addHours(8)->min(now())->format('Y-m-d\TH:i'),
            'sortKey' => ($session->check_in_at?->format('Y-m-d H:i:s') ?? $session->date->toDateString().' 00:00:00').'|a'.$session->id,
            'userId' => $session->user_id,
            'date' => $session->date->toDateString(),
            'employee' => ['name' => $session->user?->name ?? 'Unknown', 'role' => $session->user?->role],
            'job' => $session->job?->name,
            'checkIn' => [
                'at' => $session->check_in_at?->format(self::DATE_TIME),
                'note' => $this->methodNote($session->check_in_method, $session->check_in_distance_meters),
            ],
            'checkOut' => [
                'at' => $session->check_out_at?->format(self::DATE_TIME),
                'note' => $open ? null : $this->methodNote($session->check_out_method, $session->check_out_distance_meters),
            ],
            'hours' => $open ? null : round($session->workingSeconds() / 3600, 2),
            'source' => $this->attendanceSource($session->check_in_method),
            'sourceKind' => $session->check_in_method === JobAttendance::METHOD_AUTOMATIC ? 'geofence' : 'manual',
            // Saved on the phone and sent later; something a person should look at; a reported problem.
            'recordedOffline' => $session->checkInWasOffline() || $session->checkOutWasOffline(),
            'reviewFlag' => $session->review_flag,
            'openReports' => $session->corrections()->where('kind', 'correction')->where('status', 'open')->count(),
            'status' => $missing ? 'missing-checkout' : ($open ? 'on-site' : 'completed'),
        ];
    }

    /** @return array<string, mixed> */
    private function entryRow(TimeEntry $entry, ?User $viewer): array
    {
        $day = $entry->date->toDateString();
        $start = $entry->start_time ? Carbon::parse($day.' '.$entry->start_time) : null;
        $end = $entry->end_time ? Carbon::parse($day.' '.$entry->end_time) : null;

        return [
            'key' => 'entry-'.$entry->id,
            'attendanceId' => null,
            'entryId' => $entry->id,
            'canApprove' => $viewer !== null && $this->policy->approve($viewer, $entry),
            'canCheckOut' => false,
            'checkOutMin' => null,
            'checkOutSuggested' => null,
            'sortKey' => ($start?->format('Y-m-d H:i:s') ?? $day.' 00:00:00').'|e'.$entry->id,
            'userId' => $entry->user_id,
            'date' => $day,
            'employee' => [
                'name' => $entry->teamMember?->name ?? $entry->user?->name ?? 'Unknown',
                'role' => $entry->user?->role ?? $entry->teamMember?->role,
            ],
            'job' => $entry->job?->name,
            'checkIn' => ['at' => $start?->format(self::DATE_TIME), 'note' => $this->entryNote($entry)],
            'checkOut' => ['at' => $end?->format(self::DATE_TIME), 'note' => $this->entryNote($entry)],
            'hours' => round((float) $entry->hours, 2),
            'source' => $entry->source === TimeEntry::SOURCE_TIMER ? 'Timer' : 'Manual entry',
            'sourceKind' => $entry->source === TimeEntry::SOURCE_TIMER ? 'timer' : 'manual',
            'status' => match ($entry->status) {
                TimeEntry::STATUS_APPROVED, TimeEntry::STATUS_LOCKED => 'approved',
                TimeEntry::STATUS_REJECTED => 'rejected',
                TimeEntry::STATUS_SUBMITTED => 'pending',
                // A finished session is waiting on a manager, submitted or not.
                default => $entry->end_time !== null ? 'pending' : 'draft',
            },
        ];
    }

    private function entryNote(TimeEntry $entry): string
    {
        return $entry->source === TimeEntry::SOURCE_TIMER ? 'Timer' : 'Manual entry';
    }

    private function attendanceSource(?string $method): string
    {
        return match ($method) {
            JobAttendance::METHOD_AUTOMATIC => 'Auto (Geofence)',
            JobAttendance::METHOD_PHOTO => 'Photo check-in',
            default => 'Manual check-in',
        };
    }

    /** What to print under a time: how it was recorded, and how far from the site when that is known. */
    private function methodNote(?string $method, $distance): string
    {
        return match ($method) {
            JobAttendance::METHOD_AUTOMATIC => $distance !== null && (float) $distance > 0
                ? 'Geofence ('.round((float) $distance).' m from site)'
                : 'Geofence (On site)',
            JobAttendance::METHOD_PHOTO => 'Photo check-in',
            default => 'Manual',
        };
    }
}
