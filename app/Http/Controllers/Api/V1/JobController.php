<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Services\Mobile\ElectricianJobAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Jobs, as the mobile app sees them: only the ones the signed-in electrician
 * is actually staffed on (see `ElectricianJobAccess`) — a stricter scope
 * than the web app's own Job Detail page, which has no per-job restriction
 * at all. Never a budget/cost figure — those stay exclusively in the
 * manager-facing, `viewJobCosts`-gated web screens.
 */
class JobController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly ElectricianJobAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $jobs = $this->access->assignedJobsQuery($request->user())
            ->with('foreman:id,name,initials')
            ->orderBy('start_date')
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return $this->ok([
            'jobs' => $jobs->getCollection()->map(fn (Job $job) => $this->summarize($job))->all(),
            'meta' => [
                'currentPage' => $jobs->currentPage(),
                'lastPage' => $jobs->lastPage(),
                'perPage' => $jobs->perPage(),
                'total' => $jobs->total(),
            ],
        ]);
    }

    public function show(Request $request, Job $job): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $job->load(['foreman:id,name,initials', 'activeAssignments']);

        return $this->ok([
            ...$this->summarize($job),
            'description' => $job->description,
            'assignments' => $job->activeAssignments->map(fn ($a) => [
                'role' => $a->role,
                'name' => $a->name,
            ])->all(),
            'openTasksCount' => $job->tasks()->open()->count(),
        ]);
    }

    public function changeStatus(Request $request, Job $job): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $data = $request->validate([
            'status' => ['required', Rule::in(Job::STATUSES)],
        ]);

        $from = $job->status;

        // `Job::changeStatus()` is the exact same model method
        // `JobController::changeStatus()` (web) calls — it fires
        // `JobStatusChanged` itself, so the broadcast reaches Phase 8's
        // `job.{id}` channel identically regardless of which client caused it.
        $job->changeStatus($data['status']);

        return $this->ok([
            'jobId' => $job->id,
            'from' => $from,
            'to' => $job->status,
        ], $from === $job->status ? 'Status unchanged.' : 'Status updated.');
    }

    /** @return array<string, mixed> */
    private function summarize(Job $job): array
    {
        return [
            'id' => $job->id,
            'name' => $job->name,
            'client' => $job->client,
            'location' => $job->location,
            'jobType' => $job->job_type,
            'status' => $job->status,
            'priority' => $job->priority,
            'startDate' => $job->start_date?->toDateString(),
            'endDate' => $job->end_date?->toDateString(),
            'foreman' => $job->foreman ? [
                'name' => $job->foreman->name,
                'initials' => $job->foreman->initials,
            ] : null,
        ];
    }
}
