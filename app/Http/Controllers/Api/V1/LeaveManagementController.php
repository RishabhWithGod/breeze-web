<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\CrewShift;
use App\Models\Foreman;
use App\Models\LeaveRequest;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Leave\LeaveAvailability;
use App\Services\Leave\LeaveRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Manage Leave for the app: the requests the crew sent, and a manager's answer to each — web's own
 * `LeaveController`, in JSON. Approving makes the person unavailable on those days.
 */
class LeaveManagementController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly LeaveRequests $requests,
        private readonly LeaveAvailability $availability,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeManager($request);

        $companyId = $request->user()->company_id;
        $today = $this->availability->today($companyId)->toDateString();
        $base = fn () => LeaveRequest::query()->ofCompany($companyId);

        $pending = $base()->where('status', LeaveRequest::STATUS_PENDING)->orderBy('start_date')->orderBy('id')->get();
        $approved = $base()->where('status', LeaveRequest::STATUS_APPROVED)->where('end_date', '>=', $today)->orderBy('start_date')->get();
        $history = $base()
            ->where(fn ($q) => $q
                ->whereIn('status', [LeaveRequest::STATUS_REJECTED, LeaveRequest::STATUS_CANCELLED])
                ->orWhere(fn ($taken) => $taken->where('status', LeaveRequest::STATUS_APPROVED)->where('end_date', '<', $today)))
            ->orderByDesc('end_date')->orderByDesc('id')->limit(100)->get();

        $all = $pending->concat($approved)->concat($history);
        $members = $this->membersOf($all);
        $shifts = $this->shiftsFor($all);
        $present = fn (LeaveRequest $leave) => $this->present($leave, $members, $shifts, $today);

        return $this->ok([
            'today' => $today,
            'pending' => $pending->map($present)->values(),
            'approved' => $approved->map($present)->values(),
            'history' => $history->map($present)->values(),
            // Same-day cover: who else is already off on the days of each waiting request.
            'overlap' => $pending->mapWithKeys(fn (LeaveRequest $leave) => [
                $leave->id => $approved
                    ->where('user_id', '!=', $leave->user_id)
                    ->filter(fn (LeaveRequest $other) => $other->start_date->lte($leave->end_date) && $other->end_date->gte($leave->start_date))
                    ->map(fn (LeaveRequest $other) => $members[$other->user_id]['name'] ?? 'Someone')
                    ->unique()->values()->all(),
            ]),
        ]);
    }

    public function approve(Request $request, int $leave): JsonResponse
    {
        $this->authorizeManager($request);
        $row = $this->find($request, $leave);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $this->requests->approve($row, $request->user(), $data['note'] ?? null);

        return $this->ok(message: "{$row->user->name}'s leave was approved.");
    }

    public function reject(Request $request, int $leave): JsonResponse
    {
        $this->authorizeManager($request);
        $row = $this->find($request, $leave);

        $data = $request->validate(['note' => ['required', 'string', 'max:500']], [
            'note.required' => 'Tell them why, so they know what to change.',
        ]);
        $this->requests->reject($row, $request->user(), $data['note']);

        return $this->ok(message: "{$row->user->name}'s leave was turned down.");
    }

    public function revoke(Request $request, int $leave): JsonResponse
    {
        $this->authorizeManager($request);
        $row = $this->find($request, $leave);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $this->requests->revoke($row, $request->user(), $data['note'] ?? null);

        return $this->ok(message: "{$row->user->name}'s approved leave was cancelled.");
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless(LeaveRequests::isManager($request->user()), 403);
    }

    private function find(Request $request, int $id): LeaveRequest
    {
        return LeaveRequest::query()->ofCompany($request->user()->company_id)->with('user:id,name,email')->findOrFail($id);
    }

    /**
     * @param  Collection<int, LeaveRequest>  $leaves
     * @return array<int, array<string, mixed>> keyed by user id
     */
    private function membersOf(Collection $leaves): array
    {
        $ids = $leaves->pluck('user_id')->unique()->values()->all();
        $roster = Foreman::query()->whereIn('user_id', $ids)->with('team:id,name')->get()->keyBy('user_id');
        $users = User::query()->whereIn('id', $ids)->get(['id', 'name', 'email', 'role', 'status'])->keyBy('id');

        return collect($ids)->mapWithKeys(function (int $id) use ($roster, $users) {
            $member = $roster->get($id);
            $user = $users->get($id);

            return [$id => [
                'id' => $member?->id,
                'name' => $member?->name ?? $user?->name ?? 'Former member',
                'initials' => $member?->initials ?? mb_strtoupper(mb_substr((string) ($user?->name ?? '?'), 0, 2)),
                'roleLabel' => $member?->roleLabel() ?? (string) $user?->role,
                'team' => $member?->team?->name,
                'inactive' => $user?->status === User::STATUS_INACTIVE,
            ]];
        })->all();
    }

    /**
     * How many scheduled crew shifts fall inside each request — the cost of saying yes.
     *
     * @param  Collection<int, LeaveRequest>  $leaves
     * @return array<int, int> keyed by leave id
     */
    private function shiftsFor(Collection $leaves): array
    {
        $open = $leaves->filter(fn (LeaveRequest $leave) => in_array($leave->status, [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED], true));
        if ($open->isEmpty()) {
            return [];
        }

        $memberByUser = TeamMember::query()->whereIn('user_id', $open->pluck('user_id')->unique())->pluck('id', 'user_id');

        return $open->mapWithKeys(function (LeaveRequest $leave) use ($memberByUser) {
            $memberId = $memberByUser->get($leave->user_id);

            return [$leave->id => $memberId === null ? 0 : CrewShift::query()
                ->where('team_member_id', $memberId)
                ->where('status', '!=', CrewShift::STATUS_COMPLETED)
                ->whereBetween('scheduled_date', [$leave->start_date->toDateString(), $leave->end_date->toDateString()])
                ->count()];
        })->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $members
     * @param  array<int, int>  $shifts
     * @return array<string, mixed>
     */
    private function present(LeaveRequest $leave, array $members, array $shifts, string $today): array
    {
        $start = $leave->start_date->toDateString();
        $end = $leave->end_date->toDateString();

        return [
            'id' => $leave->id,
            'member' => $members[$leave->user_id] ?? null,
            'type' => $leave->type,
            'typeLabel' => $leave->typeLabel(),
            'startDate' => $start,
            'endDate' => $end,
            'days' => $leave->days(),
            'reason' => $leave->reason,
            'status' => $leave->status,
            'decisionNote' => $leave->decision_note,
            'decidedAt' => $leave->decided_at?->toISOString(),
            'requestedAt' => $leave->created_at?->toISOString(),
            'shiftsAffected' => $shifts[$leave->id] ?? 0,
            'underway' => $leave->status === LeaveRequest::STATUS_APPROVED && $start <= $today && $end >= $today,
            'canRevoke' => $leave->status === LeaveRequest::STATUS_APPROVED && $end >= $today,
        ];
    }
}
