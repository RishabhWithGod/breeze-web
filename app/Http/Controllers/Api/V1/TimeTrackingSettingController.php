<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\TimeEntry;
use App\Models\TimeTrackingSetting;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The company's overtime and default-rate rules, mobile's counterpart to
 * web's `TimeTrackingSettingController` — admin/owner only. Both
 * `OvertimeCalculator` and `LaborCostCalculator` read the same one row this
 * updates, so a change here takes effect for every entry, mobile or web.
 */
class TimeTrackingSettingController extends Controller
{
    use ApiResponses;

    public function show(Request $request): JsonResponse
    {
        $this->authorize('manageSettings', TimeEntry::class);

        return $this->ok([
            'settings' => $this->serialize(TimeTrackingSetting::current()),
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorize('manageSettings', TimeEntry::class);

        $data = $request->validate([
            'regular_daily_hours' => ['required', 'numeric', 'min:1', 'max:24'],
            'regular_weekly_hours' => ['required', 'numeric', 'min:1', 'max:168'],
            'overtime_multiplier' => ['required', 'numeric', 'min:1', 'max:5'],
            'weekend_overtime' => ['required', 'boolean'],
            'holiday_overtime' => ['required', 'boolean'],
            'holiday_dates' => ['nullable', 'array'],
            'holiday_dates.*' => ['date'],
            'default_billable_rate' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'default_cost_rate' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
        ]);

        $settings = TimeTrackingSetting::current();
        $settings->update([...$data, 'updated_by' => $request->user()->id]);

        return $this->ok(['settings' => $this->serialize($settings)], 'Time Tracking settings were updated.');
    }

    /** @return array<string, mixed> */
    private function serialize(TimeTrackingSetting $settings): array
    {
        return [
            'regularDailyHours' => (float) $settings->regular_daily_hours,
            'regularWeeklyHours' => (float) $settings->regular_weekly_hours,
            'overtimeMultiplier' => (float) $settings->overtime_multiplier,
            'weekendOvertime' => $settings->weekend_overtime,
            'holidayOvertime' => $settings->holiday_overtime,
            'holidayDates' => $settings->holiday_dates ?? [],
            'defaultBillableRate' => $settings->default_billable_rate !== null ? (float) $settings->default_billable_rate : null,
            'defaultCostRate' => $settings->default_cost_rate !== null ? (float) $settings->default_cost_rate : null,
            'timezone' => $settings->timezone,
        ];
    }
}
