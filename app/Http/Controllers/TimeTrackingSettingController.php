<?php

namespace App\Http\Controllers;

use App\Models\TimeEntry;
use App\Models\TimeTrackingSetting;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The company's overtime and default-rate rules — admin only.
 *
 * Nothing here is hard-coded business logic: `OvertimeCalculator` and
 * `LaborCostCalculator` both read the one row this screen edits.
 */
class TimeTrackingSettingController extends Controller
{
    public function edit(Request $request): Response
    {
        $this->authorize('manageSettings', TimeEntry::class);

        return Inertia::render('TimeTrackingSettings', [
            'settings' => TimeTrackingSetting::current(),
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(Request $request): RedirectResponse
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

        return back()->with('success', 'Time Tracking settings were updated.');
    }
}
