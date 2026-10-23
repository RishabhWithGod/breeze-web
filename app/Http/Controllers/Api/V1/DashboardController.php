<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\FeedItemResource;
use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\CrewShift;
use App\Models\Estimate;
use App\Models\FeedItem;
use App\Models\Job;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\Permissions;
use App\Services\Billing\InvoiceSummaryCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The phone's Dashboard — the same figures web's `DashboardController::index()` gives its
 * `Home` page (summary tiles, upcoming schedule, reviews needing attention, draft estimates,
 * billing snapshot, notification feed). A panel for a module the person's role cannot open comes
 * back `null`, so the app has nothing to leak and nothing to hide.
 */
class DashboardController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly InvoiceSummaryCalculator $invoiceSummary,
        private readonly Permissions $permissions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $can = fn (string $permission): bool => $this->permissions->allows($user, $permission);

        $summary = array_values(array_filter([
            $can('jobs.view') ? [
                'id' => 'sum_jobs',
                'value' => Job::ownedBy($user)->where('status', 'in-progress')->count(),
                'label' => 'Active Jobs',
                'icon' => 'briefcase',
                'linkLabel' => 'View all jobs',
                'target' => 'jobs',
            ] : null,
            $can('takeoff.view') ? [
                'id' => 'sum_takeoffs',
                'value' => Project::ownedBy($user)
                    ->whereHas('aiJobs', fn ($jobs) => $jobs->whereIn('status', AiJob::IN_FLIGHT))
                    ->count(),
                'label' => 'Pending AI Takeoffs',
                'icon' => 'bot',
                'linkLabel' => 'View all takeoffs',
                'target' => 'takeoffs',
            ] : null,
            $can('estimates.view') ? [
                'id' => 'sum_estimates',
                'value' => Estimate::ownedBy($user)->where('status', 'sent')->count(),
                'label' => 'Estimates Awaiting Approval',
                'icon' => 'receipt-text',
                'linkLabel' => 'View all estimates',
                'target' => 'estimates',
            ] : null,
        ]));

        return $this->ok([
            'summary' => $summary,
            'schedule' => $can('schedule.view') ? $this->upcomingSchedule($user) : null,
            'reviewsNeedingAttention' => $can('takeoff.view') ? $this->reviewsNeedingAttention($user) : null,
            'draftEstimates' => $can('estimates.view') ? $this->draftEstimates($user) : null,
            'billing' => $can('billing.view') ? $this->invoiceSummary->calculate($user) : null,
            'notificationFeed' => FeedItemResource::collection(
                FeedItem::scope(FeedItem::DASHBOARD_NOTIFICATIONS)->visibleTo($user)->limit(30)->get()
            )->resolve(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function upcomingSchedule(User $user): array
    {
        return CrewShift::query()
            ->ownedBy($user)
            ->with(['job', 'teamMember'])
            ->where('scheduled_date', '>=', now()->toDateString())
            ->orderBy('scheduled_date')
            ->orderBy('start_time')
            ->limit(30)
            ->get()
            ->map(function (CrewShift $shift) {
                $date = $shift->scheduled_date;
                $when = match (true) {
                    $date->isToday() => 'Today',
                    $date->isTomorrow() => 'Tomorrow',
                    default => $date->format('M j'),
                };

                return [
                    'id' => $shift->id,
                    'jobId' => $shift->job_id,
                    'title' => $shift->job?->name ?? 'Unassigned job',
                    'detail' => $shift->teamMember?->name ?? $shift->crew,
                    'meta' => "{$when}, {$shift->startLabel()}",
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function draftEstimates(User $user): array
    {
        return Estimate::query()
            ->ownedBy($user)
            ->where('status', 'draft')
            ->orderByDesc('issued_on')
            ->take(5)
            ->get(['id', 'client', 'project', 'number', 'issued_on', 'grand_total'])
            ->map(fn (Estimate $estimate) => [
                'id' => $estimate->id,
                'client' => $estimate->client,
                'project' => $estimate->project,
                'number' => $estimate->number,
                'issuedOn' => $estimate->issued_on?->toISOString(),
                'amount' => (float) $estimate->grand_total,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function reviewsNeedingAttention(User $user): array
    {
        return AiResult::query()
            ->whereIn('review_status', [AiResult::REVIEW_PENDING, AiResult::REVIEW_IN_PROGRESS])
            ->whereHas('project', fn ($query) => $query->ownedBy($user))
            ->with('project')
            ->orderByDesc('received_at')
            ->take(5)
            ->get()
            ->map(fn (AiResult $result) => [
                'id' => $result->id,
                'projectId' => $result->project_id,
                'projectName' => $result->project->name,
                'drawingName' => $result->project->drawing_name,
                'reviewStatus' => $result->review_status,
                'receivedAt' => $result->received_at?->toISOString(),
            ])
            ->values()
            ->all();
    }
}
