<?php

namespace App\Services\TimeTracking;

use App\Models\TimeEntry;
use App\Models\TimeTrackingSetting;
use Illuminate\Support\Carbon;

/**
 * Splits an entry's hours into regular and overtime, per the company's own
 * configurable rules (`TimeTrackingSetting`) rather than a hard-coded 8/40.
 *
 * A weekend or holiday, when the setting says so, makes the whole day
 * overtime. Otherwise the tighter of the daily and weekly thresholds — using
 * whatever the same person has already logged that day/week — governs how
 * much of *this* entry can still be regular time. This is the only place the
 * split happens; it runs on every save so a later settings change is reflected
 * the next time an entry is written, not silently baked into old rows.
 */
class OvertimeCalculator
{
    /**
     * Keyed to match `time_entries`' own columns, so a caller can `fill()` the
     * result directly without remapping.
     *
     * @return array{regular_hours: float, overtime_hours: float}
     */
    public function splitForEntry(TimeEntry $entry, TimeTrackingSetting $settings): array
    {
        $date = $entry->date instanceof Carbon ? $entry->date : Carbon::parse($entry->date);

        if ($this->isFullyOvertime($date, $settings)) {
            return ['regular_hours' => 0.0, 'overtime_hours' => round((float) $entry->hours, 2)];
        }

        $priorToday = $this->priorHours($entry, $date->copy()->startOfDay(), $date->copy()->endOfDay());
        $weekStart = $date->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $date->copy()->endOfWeek(Carbon::SUNDAY);
        $priorWeek = $this->priorHours($entry, $weekStart, $weekEnd);

        return $this->split((float) $entry->hours, $priorToday, $priorWeek, $settings);
    }

    /** @return array{regular_hours: float, overtime_hours: float} */
    private function split(float $hours, float $priorHoursToday, float $priorHoursThisWeek, TimeTrackingSetting $settings): array
    {
        $dailyRemaining = max(0.0, (float) $settings->regular_daily_hours - $priorHoursToday);
        $weeklyRemaining = max(0.0, (float) $settings->regular_weekly_hours - $priorHoursThisWeek);

        $regularCap = min($dailyRemaining, $weeklyRemaining);
        $regular = max(0.0, round(min($hours, $regularCap), 2));
        $overtime = max(0.0, round($hours - $regular, 2));

        return ['regular_hours' => $regular, 'overtime_hours' => $overtime];
    }

    private function isFullyOvertime(Carbon $date, TimeTrackingSetting $settings): bool
    {
        if ($settings->weekend_overtime && $date->isWeekend()) {
            return true;
        }

        return $settings->holiday_overtime && $settings->isHoliday($date);
    }

    /** Hours the same person already has recorded in the window, excluding this entry. */
    private function priorHours(TimeEntry $entry, Carbon $from, Carbon $to): float
    {
        return (float) TimeEntry::query()
            ->where('user_id', $entry->user_id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereNotIn('status', [TimeEntry::STATUS_REJECTED, TimeEntry::STATUS_LOCKED])
            ->when($entry->exists, fn ($query) => $query->whereKeyNot($entry->id))
            ->sum('hours');
    }
}
