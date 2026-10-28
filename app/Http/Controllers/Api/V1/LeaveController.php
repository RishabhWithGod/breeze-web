<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Services\Leave\LeaveAvailability;
use App\Services\Leave\LeaveRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Leave, from the field app: ask for time off, see the answer, take a request back.
 * A person only ever sees and touches their own; the manager's side is on the web.
 */
class LeaveController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly LeaveRequests $requests,
        private readonly LeaveAvailability $availability,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->ok([
            // What the home screen shows: on leave (and for how many more days), or available.
            'status' => $this->availability->statusFor($user->id, $user->company_id),
            'requests' => $this->mine($request)->orderByDesc('start_date')->orderByDesc('id')->limit(60)->get()
                ->map(fn (LeaveRequest $leave) => $this->present($leave))->values(),
            'types' => collect(LeaveRequest::TYPES)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values(),
            'maxDays' => LeaveRequest::MAX_DAYS,
            'today' => $this->availability->today($user->company_id)->toDateString(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(LeaveRequest::TYPES))],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $leave = $this->requests->apply($request->user(), $data);

        return $this->created($this->present($leave), 'Leave requested. Your manager will answer it.');
    }

    public function cancel(Request $request, int $leave): JsonResponse
    {
        $row = $this->mine($request)->whereKey($leave)->firstOrFail();

        return $this->ok($this->present($this->requests->cancelOwn($row)), 'Leave cancelled.');
    }

    /** @return \Illuminate\Database\Eloquent\Builder<LeaveRequest> */
    private function mine(Request $request)
    {
        $user = $request->user();

        return LeaveRequest::query()->ofCompany($user->company_id)->where('user_id', $user->id);
    }

    /** @return array<string, mixed> */
    private function present(LeaveRequest $leave): array
    {
        $today = $this->availability->today($leave->company_id)->toDateString();

        return [
            'id' => $leave->id,
            'type' => $leave->type,
            'typeLabel' => $leave->typeLabel(),
            'startDate' => $leave->start_date->toDateString(),
            'endDate' => $leave->end_date->toDateString(),
            'days' => $leave->days(),
            'reason' => $leave->reason,
            'status' => $leave->status,
            'decisionNote' => $leave->decision_note,
            'decidedAt' => $leave->decided_at?->toISOString(),
            'createdAt' => $leave->created_at?->toISOString(),
            // Waiting, or approved and not begun — the two a person may still take back.
            'canCancel' => $leave->status === LeaveRequest::STATUS_PENDING
                || ($leave->status === LeaveRequest::STATUS_APPROVED && $leave->start_date->toDateString() > $today),
        ];
    }
}
