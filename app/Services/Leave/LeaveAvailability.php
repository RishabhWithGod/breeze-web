<?php

namespace App\Services\Leave;

use App\Models\CompanyProfile;
use App\Models\LeaveRequest;
use Carbon\CarbonImmutable;

/**
 * Whether someone is available today, and if not, how many days until they are.
 *
 * Nothing here is stored: the count is worked out from the approved dates and today's date
 * in the company's own time zone, so it is one lower every morning without anything running
 * overnight — and it is the same figure on the web and on the phone.
 *
 * Leave that follows straight on from other leave (one ends Friday, the next starts Saturday)
 * is read as one absence, so "available in N days" is the day the person is really back.
 */
class LeaveAvailability
{
    public const ON_LEAVE = 'on_leave';

    public const UPCOMING = 'upcoming';

    public const PENDING = 'pending';

    public const AVAILABLE = 'available';

    /** Today, as a calendar day in the company's time zone. */
    public function today(?int $companyId): CarbonImmutable
    {
        $zone = $companyId !== null ? CompanyProfile::query()->whereKey($companyId)->value('timezone') : null;

        return CarbonImmutable::now($zone ?: config('app.timezone'))->startOfDay();
    }

    /**
     * @return array<string, mixed>
     */
    public function statusFor(int $userId, ?int $companyId): array
    {
        return $this->statusMap([$userId], $companyId)[$userId];
    }

    /**
     * Everyone's availability in one query.
     *
     * @param  list<int>  $userIds
     * @return array<int, array<string, mixed>> keyed by user id; every id asked for is present
     */
    public function statusMap(array $userIds, ?int $companyId): array
    {
        $today = $this->today($companyId);
        $todayText = $today->toDateString();
        // From here on a day is just a day: all dates are read at UTC midnight so that the
        // company's time zone never shifts one across a boundary.
        $day = CarbonImmutable::parse($todayText, 'UTC');

        $byUser = $userIds === [] ? collect() : LeaveRequest::query()
            ->ofCompany($companyId)
            ->whereIn('user_id', $userIds)
            ->holding()
            ->where('end_date', '>=', $todayText)
            ->orderBy('start_date')
            ->get()
            ->groupBy('user_id');

        $map = [];
        foreach ($userIds as $userId) {
            $map[$userId] = $this->resolve($byUser->get($userId, collect()), $day);
        }

        return $map;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, LeaveRequest>  $requests  the person's open requests, earliest first
     * @return array<string, mixed>
     */
    private function resolve($requests, CarbonImmutable $today): array
    {
        $approved = $requests->where('status', LeaveRequest::STATUS_APPROVED)->values();
        $pending = $requests->where('status', LeaveRequest::STATUS_PENDING)->count();

        $status = [
            'state' => $pending > 0 ? self::PENDING : self::AVAILABLE,
            'daysLeft' => null,
            'availableOn' => null,
            'startsIn' => null,
            'startsOn' => null,
            'endsOn' => null,
            'type' => null,
            'typeLabel' => null,
            'pending' => $pending,
        ];

        $current = $approved->first(fn (LeaveRequest $leave) => $this->day($leave->start_date)->lte($today) && $this->day($leave->end_date)->gte($today));

        if ($current !== null) {
            $end = $this->throughFollowing($approved, $current);

            return [
                'state' => self::ON_LEAVE,
                // Today counts: on the last day of leave, the person is available tomorrow — 1 day.
                'daysLeft' => (int) $today->diffInDays($end) + 1,
                'availableOn' => $end->addDay()->toDateString(),
                'endsOn' => $end->toDateString(),
                'type' => $current->type,
                'typeLabel' => $current->typeLabel(),
            ] + $status;
        }

        $next = $approved->first(fn (LeaveRequest $leave) => $this->day($leave->start_date)->gt($today));

        if ($next !== null) {
            $end = $this->throughFollowing($approved, $next);

            return [
                'state' => self::UPCOMING,
                'startsIn' => (int) $today->diffInDays($this->day($next->start_date)),
                'startsOn' => $next->start_date->toDateString(),
                'endsOn' => $end->toDateString(),
                'availableOn' => $end->addDay()->toDateString(),
                'type' => $next->type,
                'typeLabel' => $next->typeLabel(),
            ] + $status;
        }

        return $status;
    }

    /**
     * The last day of the absence that `$first` begins, following on from any leave that starts
     * the day it ends (or overlaps it).
     *
     * @param  \Illuminate\Support\Collection<int, LeaveRequest>  $approved
     */
    private function throughFollowing($approved, LeaveRequest $first): CarbonImmutable
    {
        $end = $this->day($first->end_date);

        do {
            $extended = false;
            foreach ($approved as $leave) {
                if ($this->day($leave->start_date)->lte($end->addDay()) && $this->day($leave->end_date)->gt($end)) {
                    $end = $this->day($leave->end_date);
                    $extended = true;
                }
            }
        } while ($extended);

        return $end;
    }

    /** A stored date as a calendar day at UTC midnight. */
    private function day(\DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->format('Y-m-d'), 'UTC');
    }
}
