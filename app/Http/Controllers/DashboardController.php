<?php

namespace App\Http\Controllers;

use App\Http\Resources\FeedItemResource;
use App\Models\FeedItem;
use App\Models\Job;
use App\Models\PerformancePoint;
use App\Models\Project;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Home', [
            'summary' => $this->summary(),
            // resolve() keeps these as plain arrays — only paginated props need
            // the data/meta envelope.
            'activity' => FeedItemResource::collection(
                FeedItem::scope(FeedItem::DASHBOARD_ACTIVITY)->get()
            )->resolve(),
            // Not `notifications` — that name is taken by the shared prop the
            // header bell reads, and a page prop would shadow it.
            'notificationFeed' => FeedItemResource::collection(
                FeedItem::scope(FeedItem::DASHBOARD_NOTIFICATIONS)->get()
            )->resolve(),
            'schedule' => FeedItemResource::collection(
                FeedItem::scope(FeedItem::DASHBOARD_SCHEDULE)->get()
            )->resolve(),
            'performance' => PerformancePoint::orderBy('position')
                ->get(['month', 'value'])
                ->all(),
        ]);
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
