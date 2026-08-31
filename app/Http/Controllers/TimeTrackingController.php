<?php

namespace App\Http\Controllers;

use App\Services\TimeTracking\TimesheetWeekBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The personal weekly timesheet grid (`/time-tracking/week`) — "how was my
 * week", the one-person view. The module's landing screen itself is the
 * entries list, served by `TimeEntryController::index()`.
 */
class TimeTrackingController extends Controller
{
    public function __construct(
        private readonly TimesheetWeekBuilder $weekBuilder,
    ) {}

    public function week(Request $request): Response
    {
        $data = $request->validate(['date' => ['nullable', 'date']]);

        $anchor = isset($data['date']) ? Carbon::parse($data['date']) : now();
        $week = $this->weekBuilder->build($request->user(), $anchor);

        return Inertia::render('TimeTrackingWeek', [
            'week' => $week,
            'prevWeekDate' => $anchor->copy()->subWeek()->toDateString(),
            'nextWeekDate' => $anchor->copy()->addWeek()->toDateString(),
            'thisWeekDate' => now()->toDateString(),
        ]);
    }
}
