<?php

namespace App\Http\Controllers;

use App\Http\Resources\FeedItemResource;
use App\Models\CrewShift;
use App\Models\FeedItem;
use App\Models\Job;
use App\Models\Project;
use App\Services\Dashboard\JobPerformanceCalculator;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly JobPerformanceCalculator $performance) {}

    public function index(): Response
    {
        return Inertia::render('Home', [
            'summary' => $this->summary(),
            // resolve() keeps these as plain arrays — only paginated props need
            // the data/meta envelope. Uncapped — the card itself scrolls
            // rather than truncating the list.
            'activity' => FeedItemResource::collection(
                FeedItem::scope(FeedItem::DASHBOARD_ACTIVITY)->get()
            )->resolve(),
            // Not `notifications` — that name is taken by the shared prop the
            // header bell reads, and a page prop would shadow it.
            'notificationFeed' => FeedItemResource::collection(
                FeedItem::scope(FeedItem::DASHBOARD_NOTIFICATIONS)->get()
            )->resolve(),
            'schedule' => $this->upcomingSchedule(),
            'performance' => $this->performance->series(),
        ]);
    }

    /**
     * Every upcoming crew shift on the calendar, straight from `crew_shifts` —
     * real bookings, not the generic activity feed (which is written in the
     * past tense and has nothing to do with what's coming up). Uncapped — the
     * card itself scrolls rather than truncating the list.
     *
     * @return list<array<string, mixed>>
     */
    private function upcomingSchedule(): array
    {
        return CrewShift::query()
            ->with(['job', 'teamMember'])
            ->where('scheduled_date', '>=', now()->toDateString())
            ->orderBy('scheduled_date')
            ->orderBy('start_time')
            ->get()
            ->map(function (CrewShift $shift) {
                /** @var Carbon $date */
                $date = $shift->scheduled_date;
                $when = match (true) {
                    $date->isToday() => 'Today',
                    $date->isTomorrow() => 'Tomorrow',
                    default => $date->format('m/d'),
                };

                return [
                    'id' => $shift->id,
                    'segments' => [
                        ['text' => $shift->job?->name ?? 'Unassigned job', 'strong' => true],
                    ],
                    'detail' => $shift->teamMember?->name ?? $shift->crew,
                    'meta' => "{$when}, {$shift->startLabel()}",
                    'icon' => 'calendar-check',
                    'tile' => 'butter',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The three headline tiles. Counted from the database rather than hardcoded,
     * so the figures always match what the Jobs and History screens list.
     *
     * @return list<array<string, mixed>>
     */
    private function summary(): array
    {
        return [
            [
                'id' => 'sum_jobs',
                'value' => Job::where('status', 'in-progress')->count(),
                'label' => 'Active Jobs',
                'icon' => 'briefcase',
                'linkLabel' => 'View all jobs',
                'href' => route('jobs.index', absolute: false),
            ],
            [
                'id' => 'sum_takeoffs',
                'value' => Project::whereIn('status', ['processing', 'draft'])->count(),
                'label' => 'Pending AI Takeoffs',
                'icon' => 'bot',
                'linkLabel' => 'View all takeoffs',
                'href' => route('takeoffs.index', absolute: false),
            ],
            [
                'id' => 'sum_estimates',
                'value' => Project::where('status', 'completed')->count(),
                'label' => 'Estimates Awaiting Approval',
                'icon' => 'receipt-text',
                'linkLabel' => 'View all estimates',
                'href' => route('results.latest', absolute: false),
            ],
        ];
    }
}
