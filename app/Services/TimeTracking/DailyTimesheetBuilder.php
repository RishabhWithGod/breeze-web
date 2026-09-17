<?php

namespace App\Services\TimeTracking;

use App\Models\JobAttendance;
use App\Models\TeamMember;
use App\Models\TimeEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * One row per technician per calendar day — not one row per timer session
 * and not one row per GPS check-in.
 *
 * A technician who starts and stops their timer four times, or checks in
 * and out of two different jobs, still worked one day; the Time Log screen
 * shows that as a single total, with every session behind it available on
 * that day's own detail screen (`TimeEntryController::showDay()`).
 *
 * `time_entries` and `job_attendances` are untouched by this — nothing here
 * changes what either table stores, how a timer session is created, or how
 * approval works session by session. This only groups what already exists,
 * for the list view alone.
 */
class DailyTimesheetBuilder
{
    /**
     * @param  array<string, mixed>  $filters  Same shape `TimeEntryController::filtered()` validates.
     */
    public function paginate(
        array $filters,
        bool $canViewCrew,
        int $viewerId,
        int $perPage,
        int $page
    ): LengthAwarePaginatorContract {
        $entryQuery = $this->entryQuery($filters, $canViewCrew, $viewerId);
        $attendanceQuery = $this->attendanceQuery($filters, $canViewCrew, $viewerId);

        // Every (user, date) pair with something recorded — the key the list
        // groups on. Two small `distinct` queries rather than a SQL UNION:
        // the two tables share no key to join on, and this reads far more
        // plainly than emulating a UNION through the query builder.
        $keys = $entryQuery->clone()->select('user_id', 'date')->distinct()->get()
            ->concat($attendanceQuery->clone()->select('user_id', 'date')->distinct()->get())
            ->unique(fn ($row) => $row->user_id.'|'.$row->date->toDateString())
            ->sortByDesc(fn ($row) => $row->date->toDateString().'|'.$row->user_id)
            ->values();

        $total = $keys->count();
        $page = max(1, $page);
        $pageKeys = $keys->slice(($page - 1) * $perPage, $perPage);

        if ($pageKeys->isEmpty()) {
            return new LengthAwarePaginator([], $total, $perPage, $page, [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
            ]);
        }

        // Only the rows behind this page's (user, date) pairs — bounded by
        // however many technicians and days actually landed on this page,
        // never the whole filtered range.
        $userIds = $pageKeys->pluck('user_id')->unique()->values();
        $dates = $pageKeys->pluck('date')->map(fn ($d) => $d->toDateString())->unique()->values();

        $entries = $entryQuery->clone()
            ->whereIn('user_id', $userIds)
            ->whereIn('date', $dates)
            ->with(['job:id,name', 'user:id,name,role', 'teamMember:id,name,role'])
            ->get()
            ->groupBy(fn (TimeEntry $e) => $e->user_id.'|'.$e->date->toDateString());

        $attendance = $attendanceQuery->clone()
            ->whereIn('user_id', $userIds)
            ->whereIn('date', $dates)
            ->with(['job:id,name', 'user:id,name,role'])
            ->get()
            ->groupBy(fn (JobAttendance $a) => $a->user_id.'|'.$a->date->toDateString());

        $rows = $pageKeys->map(function ($key) use ($entries, $attendance) {
            $groupKey = $key->user_id.'|'.$key->date->toDateString();

            return $this->summarize(
                (int) $key->user_id,
                $key->date->toDateString(),
                $entries->get($groupKey, collect()),
                $attendance->get($groupKey, collect()),
            );
        })->all();

        return new LengthAwarePaginator($rows, $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
        ]);
    }

    /**
     * @param  Collection<int, TimeEntry>  $entries
     * @param  Collection<int, JobAttendance>  $attendance
     * @return array<string, mixed>
     */
    private function summarize(int $userId, string $date, Collection $entries, Collection $attendance): array
    {
        /** @var TimeEntry|JobAttendance|null $any */
        $any = $entries->first() ?? $attendance->first();

        $entryHours = round((float) $entries->sum('hours'), 2);
        $attendanceHours = round($attendance->sum(fn (JobAttendance $a) => $a->workingSeconds()) / 3600, 2);

        $jobs = $entries->map(fn (TimeEntry $e) => $e->job?->name)
            ->concat($attendance->map(fn (JobAttendance $a) => $a->job?->name))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'userId' => $userId,
            'date' => $date,
            'employee' => [
                'name' => $entries->first()?->teamMember?->name ?? $any?->user?->name ?? 'Unknown',
                'role' => $any?->user?->role,
            ],
            'jobs' => $jobs,
            'totalHours' => round($entryHours + $attendanceHours, 2),
            'sessionCount' => $entries->count() + $attendance->count(),
            'status' => $this->aggregateStatus($entries),
            'hasTimerEntries' => $entries->isNotEmpty(),
            'hasAttendance' => $attendance->isNotEmpty(),
            'attendanceStatus' => $this->aggregateAttendanceStatus($attendance),
        ];
    }

    /**
     * Whether the day's GPS attendance is still open or fully closed out —
     * "on site" beats "checked out" the same way a pending session beats an
     * approved one in {@see aggregateStatus()}: one still-open check-in means
     * the day is not done, no matter how many other sites were checked out of.
     *
     * Public: `TimeEntryController::showDay()` renders the same rule on the
     * day detail screen, so the list and the detail screen never disagree.
     *
     * @param  Collection<int, JobAttendance>  $attendance
     */
    public function aggregateAttendanceStatus(Collection $attendance): ?string
    {
        if ($attendance->isEmpty()) {
            return null;
        }

        return $attendance->contains(fn (JobAttendance $a) => $a->isCheckedIn())
            ? JobAttendance::STATUS_CHECKED_IN
            : JobAttendance::STATUS_CHECKED_OUT;
    }

    /**
     * One status for the whole day out of however many sessions it holds —
     * the same "worst wins" instinct an approver already applies by eye:
     * anything rejected needs a look first, anything still pending is not
     * done yet, and only a day where every session is approved (or locked
     * behind an approved correction) reads as fully approved. A day built
     * entirely from GPS check-ins, with no timer session at all, has no
     * approval workflow to report — `null` here, `hasTimerEntries` is what a
     * caller checks instead.
     *
     * Public: `TimeEntryController::showDay()` renders the same rule on the
     * day detail screen's own status chip — the list and the detail screen
     * must never disagree about what a day's status is.
     *
     * @param  Collection<int, TimeEntry>  $entries
     */
    public function aggregateStatus(Collection $entries): ?string
    {
        if ($entries->isEmpty()) {
            return null;
        }

        $statuses = $entries->pluck('status')->unique();

        if ($statuses->contains(TimeEntry::STATUS_REJECTED)) {
            return 'rejected';
        }
        if ($statuses->contains(TimeEntry::STATUS_DRAFT) || $statuses->contains(TimeEntry::STATUS_SUBMITTED)) {
            return 'pending';
        }
        if ($statuses->every(fn ($s) => in_array($s, [TimeEntry::STATUS_APPROVED, TimeEntry::STATUS_LOCKED], true))) {
            return 'approved';
        }

        return 'mixed';
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function entryQuery(array $filters, bool $canViewCrew, int $viewerId)
    {
        $status = $filters['status'] ?? 'all';
        $billable = $filters['billable'] ?? 'all';
        $taskType = $filters['task_type'] ?? 'all';

        return TimeEntry::query()
            ->when(! $canViewCrew, fn ($q) => $q->where('user_id', $viewerId))
            ->search($filters['search'] ?? null)
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('date', '<=', $filters['to']))
            ->when(! empty($filters['job']), fn ($q) => $q->where('job_id', $filters['job']))
            ->when(! empty($filters['team_member']), fn ($q) => $q->where('team_member_id', $filters['team_member']))
            ->when($taskType !== 'all', fn ($q) => $q->whereHas('jobTask', fn ($t) => $t->where('category', $taskType)))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($billable !== 'all', fn ($q) => $q->where('billable', $billable === 'yes'));
    }

    /**
     * Same date/job/employee narrowing as {@see entryQuery()} — `status`,
     * `task_type` and `billable` have no meaning for a GPS check-in and are
     * not applied here, exactly as the list treated them before this
     * grouping existed. `team_member` is resolved to the underlying user,
     * since `job_attendances` has no `team_member_id` column of its own.
     *
     * @param  array<string, mixed>  $filters
     */
    private function attendanceQuery(array $filters, bool $canViewCrew, int $viewerId)
    {
        $teamMemberUserId = ! empty($filters['team_member'])
            ? TeamMember::find($filters['team_member'])?->user_id
            : null;

        return JobAttendance::query()
            ->when(! $canViewCrew, fn ($q) => $q->where('user_id', $viewerId))
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('date', '<=', $filters['to']))
            ->when(! empty($filters['job']), fn ($q) => $q->where('job_id', $filters['job']))
            ->when($teamMemberUserId !== null, fn ($q) => $q->where('user_id', $teamMemberUserId))
            // A `team_member` filter with no linked user must exclude every
            // attendance row rather than silently ignoring the filter.
            ->when(
                ! empty($filters['team_member']) && $teamMemberUserId === null,
                fn ($q) => $q->whereRaw('1 = 0')
            );
    }
}
