<?php

namespace App\Services\TimeTracking;

use App\Events\TimeEntryLogged;
use App\Models\Job;
use App\Models\JobTask;
use App\Models\TimeEntry;
use App\Models\TimeTrackingSetting;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits a time entry: resolves the hours (from times, or as
 * typed), applies overtime/labor-cost, and records the audit trail —
 * exactly once, so the web form and the mobile API can never compute a
 * different number for the same entry.
 *
 * Takes already-`$request->validate()`d data rather than a `Request`, since
 * a web form and a mobile JSON body validate slightly different shapes
 * (the web form's native `<input type="time">` only round-trips to the
 * minute) but must still agree on what happens once the data is valid.
 */
class TimeEntryWriteService
{
    public function __construct(
        private readonly TeamMemberResolver $resolver,
        private readonly TimeEntryCalculator $calculator,
        private readonly OvertimeCalculator $overtime,
        private readonly LaborCostCalculator $cost,
    ) {}

    /**
     * @param  array{job_id: int, job_task_id?: int|null, task_label?: string|null, date: string, start_time?: string|null, end_time?: string|null, break_minutes?: int|null, hours?: float|null, description?: string|null, billable?: bool|null}  $data
     */
    public function save(array $data, User $actor, TimeEntry $entry): TimeEntry
    {
        $isNew = ! $entry->exists;

        $job = Job::findOrFail($data['job_id']);
        $task = ! empty($data['job_task_id']) ? JobTask::findOrFail($data['job_task_id']) : null;

        if ($task !== null && $task->job_id !== $job->id) {
            throw ValidationException::withMessages([
                'job_task_id' => 'That task does not belong to the selected job.',
            ]);
        }

        $breakMinutes = (int) ($data['break_minutes'] ?? 0);

        /*
         * A timer stores start/end to the second; a web form's native time
         * inputs only round-trip to the minute. Re-saving an entry without
         * actually touching its times must not resend a lower-precision
         * value that can collide (a sub-minute entry would otherwise fail
         * "end after start" on every subsequent edit) — so only recompute
         * when the minute-level value submitted actually differs from what
         * is already stored.
         */
        $timesUnchanged = ! $isNew
            && filled($data['start_time'] ?? null)
            && filled($data['end_time'] ?? null)
            && $data['start_time'] === substr((string) $entry->start_time, 0, 5)
            && $data['end_time'] === substr((string) $entry->end_time, 0, 5)
            && $breakMinutes === $entry->break_minutes;

        if ($timesUnchanged) {
            $hours = (float) $entry->hours;
        } elseif (filled($data['start_time'] ?? null) && filled($data['end_time'] ?? null)) {
            $hours = $this->calculator->fromTimes($data['date'], $data['start_time'], $data['end_time'], $breakMinutes);
        } elseif (isset($data['hours'])) {
            $hours = (float) $data['hours'];
            $this->calculator->assertHoursValid($hours);
        } else {
            throw ValidationException::withMessages([
                'hours' => 'Enter either a start and end time, or the hours worked.',
            ]);
        }

        $teamMember = $isNew ? $this->resolver->resolveFor($actor) : $entry->teamMember;

        $entry->fill([
            'job_id' => $job->id,
            'job_task_id' => $task?->id,
            'user_id' => $isNew ? $actor->id : $entry->user_id,
            'team_member_id' => $teamMember?->id,
            'date' => $data['date'],
            // Keep the stored (second-precision) value when the times were
            // not actually changed; otherwise take the new minute-level input.
            'start_time' => $timesUnchanged ? $entry->start_time : ($data['start_time'] ?? null),
            'end_time' => $timesUnchanged ? $entry->end_time : ($data['end_time'] ?? null),
            'break_minutes' => $breakMinutes,
            'hours' => $hours,
            'task_label' => $task ? null : ($data['task_label'] ?? null),
            'description' => $data['description'] ?? null,
            'billable' => (bool) ($data['billable'] ?? true),
            'source' => $isNew ? TimeEntry::SOURCE_MANUAL : $entry->source,
            'status' => $isNew ? TimeEntry::STATUS_DRAFT : $entry->status,
        ]);
        $entry->save();
        $entry->load('teamMember');

        $settings = TimeTrackingSetting::current();
        $entry->fill($this->overtime->splitForEntry($entry, $settings));
        $this->cost->apply($entry, $settings);
        $entry->save();

        if ($isNew) {
            $entry->recordInitialStatus();
            $entry->recordActivity('created', 'Time entry logged.');
        } else {
            $entry->recordActivity('edited', 'Time entry updated.');
        }

        TimeEntryLogged::dispatch($entry, $isNew ? 'created' : 'updated');

        return $entry;
    }
}
