<?php

namespace App\Services\Leave;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveDecided;
use App\Notifications\LeaveRequested;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Asking for leave, and what a manager does with the ask. One set of rules for the phone
 * and the web, so neither can do something the other would refuse.
 */
class LeaveRequests
{
    /** Roles that decide on leave: the same people who approve technicians. */
    public const MANAGER_ROLES = ['project manager', 'admin', 'owner'];

    public function __construct(private readonly LeaveAvailability $availability) {}

    public static function isManager(?User $user): bool
    {
        return $user !== null && in_array(mb_strtolower(trim((string) $user->role)), self::MANAGER_ROLES, true);
    }

    /**
     * Records a request and tells the company's managers.
     *
     * @param  array{type: string, start_date: string, end_date: string, reason?: ?string}  $data
     *
     * @throws ValidationException when the days are in the past, too long or already taken
     */
    public function apply(User $user, array $data): LeaveRequest
    {
        $today = $this->availability->today($user->company_id)->toDateString();

        if ($data['start_date'] < $today) {
            throw ValidationException::withMessages(['start_date' => 'Leave cannot start in the past.']);
        }

        if ($data['end_date'] < $data['start_date']) {
            throw ValidationException::withMessages(['end_date' => 'The last day cannot be before the first day.']);
        }

        $days = (int) \Carbon\CarbonImmutable::parse($data['start_date'])->diffInDays($data['end_date']) + 1;
        if ($days > LeaveRequest::MAX_DAYS) {
            throw ValidationException::withMessages(['end_date' => 'A request can cover at most '.LeaveRequest::MAX_DAYS.' days. Ask for the rest separately.']);
        }

        $clash = LeaveRequest::query()
            ->ofCompany($user->company_id)
            ->where('user_id', $user->id)
            ->holding()
            ->overlapping($data['start_date'], $data['end_date'])
            ->first();

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'start_date' => 'You already have leave on '.$clash->start_date->format('M j').($clash->days() > 1 ? ' – '.$clash->end_date->format('M j') : '').'. Pick other days or cancel that request first.',
            ]);
        }

        $leave = LeaveRequest::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'type' => $data['type'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'reason' => filled($data['reason'] ?? null) ? trim((string) $data['reason']) : null,
            'status' => LeaveRequest::STATUS_PENDING,
        ]);

        $managers = User::query()
            ->whereRaw('lower(trim(role)) in (?, ?, ?)', self::MANAGER_ROLES)
            ->where('status', User::STATUS_ACTIVE)
            ->when(
                $user->company_id !== null,
                fn ($query) => $query->where('company_id', $user->company_id),
                fn ($query) => $query->whereNull('company_id'),
            )
            ->get();

        $leave->setRelation('user', $user);
        Notification::send($managers, new LeaveRequested($leave));

        return $leave;
    }

    /**
     * The person takes their own request back: while it waits, or before the leave has begun.
     *
     * @throws ValidationException when it is already over or under way
     */
    public function cancelOwn(LeaveRequest $leave): LeaveRequest
    {
        $today = $this->availability->today($leave->company_id)->toDateString();

        $open = $leave->status === LeaveRequest::STATUS_PENDING
            || ($leave->status === LeaveRequest::STATUS_APPROVED && $leave->start_date->toDateString() > $today);

        if (! $open) {
            throw ValidationException::withMessages(['leave' => 'Only leave that has not started yet can be cancelled. Talk to your manager about the rest.']);
        }

        $leave->update(['status' => LeaveRequest::STATUS_CANCELLED]);

        return $leave;
    }

    /** @throws ValidationException when it is not waiting for a decision */
    public function approve(LeaveRequest $leave, User $manager, ?string $note): LeaveRequest
    {
        return $this->decide($leave, $manager, LeaveRequest::STATUS_APPROVED, $note);
    }

    /** @throws ValidationException when it is not waiting for a decision */
    public function reject(LeaveRequest $leave, User $manager, ?string $note): LeaveRequest
    {
        return $this->decide($leave, $manager, LeaveRequest::STATUS_REJECTED, $note);
    }

    /**
     * Takes back leave already granted, for days that have not fully passed.
     *
     * @throws ValidationException when it was not approved or is already over
     */
    public function revoke(LeaveRequest $leave, User $manager, ?string $note): LeaveRequest
    {
        $today = $this->availability->today($leave->company_id)->toDateString();

        if ($leave->status !== LeaveRequest::STATUS_APPROVED || $leave->end_date->toDateString() < $today) {
            throw ValidationException::withMessages(['leave' => 'Only approved leave that has not ended can be taken back.']);
        }

        return $this->decide($leave, $manager, LeaveRequest::STATUS_CANCELLED, $note, from: LeaveRequest::STATUS_APPROVED);
    }

    private function decide(LeaveRequest $leave, User $manager, string $status, ?string $note, string $from = LeaveRequest::STATUS_PENDING): LeaveRequest
    {
        // One conditional write, so two managers answering at once cannot both win.
        $changed = LeaveRequest::query()->whereKey($leave->id)->where('status', $from)->update([
            'status' => $status,
            'decided_by' => $manager->id,
            'decided_at' => now(),
            'decision_note' => filled($note) ? trim((string) $note) : null,
            'updated_at' => now(),
        ]);

        if ($changed === 0) {
            throw ValidationException::withMessages(['leave' => 'This request has already been decided.']);
        }

        $leave->refresh();
        $leave->loadMissing('user');
        $leave->user?->notify(new LeaveDecided($leave));

        return $leave;
    }
}
