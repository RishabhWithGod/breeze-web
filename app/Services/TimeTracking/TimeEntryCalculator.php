<?php

namespace App\Services\TimeTracking;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Turns a start/end/break into hours, and enforces the three rules that keep a
 * time entry honest: end cannot be before start, a break cannot exceed the
 * span it sits inside, and hours cannot be negative.
 *
 * The one place this math happens. `TimeEntryController` and `TimerService`
 * both call it, so the frontend's own duration preview is never a second,
 * possibly-disagreeing source of truth — it only echoes what the server
 * confirms.
 */
class TimeEntryCalculator
{
    /** @param  string  $date  Y-m-d, used only to anchor start/end for the diff. */
    public function fromTimes(string $date, ?string $startTime, ?string $endTime, int $breakMinutes): float
    {
        if ($startTime === null || $endTime === null) {
            throw ValidationException::withMessages([
                'end_time' => 'A start and an end time are both needed to calculate hours.',
            ]);
        }

        $start = Carbon::parse("{$date} {$startTime}");
        $end = Carbon::parse("{$date} {$endTime}");

        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages([
                'end_time' => 'End time cannot be before the start time.',
            ]);
        }

        $totalMinutes = $start->diffInMinutes($end);

        $this->assertBreakFits($breakMinutes, $totalMinutes);

        return round(($totalMinutes - $breakMinutes) / 60, 2);
    }

    public function assertBreakFits(int $breakMinutes, int $totalMinutes): void
    {
        if ($breakMinutes > $totalMinutes) {
            throw ValidationException::withMessages([
                'break_minutes' => 'The break cannot be longer than the time worked.',
            ]);
        }
    }

    public function assertHoursValid(float $hours): void
    {
        if ($hours < 0) {
            throw ValidationException::withMessages([
                'hours' => 'Hours cannot be negative.',
            ]);
        }
    }
}
