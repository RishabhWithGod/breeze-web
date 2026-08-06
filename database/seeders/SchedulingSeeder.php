<?php

namespace Database\Seeders;

use App\Models\CrewShift;
use App\Models\Job;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Fills in the scheduling fields and books a week of crew shifts.
 *
 * Additive and re-runnable: jobs that already carry a priority or a booked shift
 * are left exactly as they are, so running this a second time cannot duplicate a
 * crew's day or overwrite a real schedule.
 */
class SchedulingSeeder extends Seeder
{
    /** Skills offered per job type, so the required-skills chips read plausibly. */
    private const SKILLS = [
        'commercial' => [
            ['Commercial Electric', 'Panel Installation', 'High Voltage'],
            ['Commercial Electric', 'Switchgear', 'Load Calculation'],
            ['Commercial Electric', 'Fire Alarm', 'Emergency Lighting'],
        ],
        'residential' => [
            ['Residential Wiring', 'Panel Upgrade', 'Code Compliance'],
            ['Residential Wiring', 'Lighting Design', 'Smart Home'],
            ['Residential Wiring', 'EV Charger', 'Grounding'],
        ],
        'industrial' => [
            ['Industrial Controls', 'Motor Control', 'PLC Wiring'],
            ['Industrial Controls', 'Conduit Bending', 'Three Phase'],
        ],
    ];

    /** Crew labels, cycled so the calendar shows more than one team. */
    private const CREWS = ['Team A', 'Team B', 'Team C'];

    public function run(): void
    {
        $this->backfillJobFields();
        $this->bookShifts();
    }

    /**
     * Gives every job the three fields the unassigned queue ranks and filters by.
     *
     * Priority is derived from the job's own budget and status rather than assigned
     * at random, so the ordering on screen means something.
     */
    private function backfillJobFields(): void
    {
        Job::query()->whereNull('required_skills')->chunkById(100, function ($jobs) {
            foreach ($jobs as $index => $job) {
                $type = in_array($job->job_type, Job::TYPES, true) ? $job->job_type : 'commercial';
                $options = self::SKILLS[$type];

                $job->forceFill([
                    'priority' => $this->priorityFor($job),
                    'estimated_hours' => $job->estimated_hours ?? $this->hoursFor($job),
                    'required_skills' => $options[$index % count($options)],
                ])->saveQuietly();
            }
        });
    }

    /** Bigger and more urgent work first — the queue's order has to be defensible. */
    private function priorityFor(Job $job): string
    {
        $budget = (float) ($job->budget ?? 0);

        return match (true) {
            in_array($job->status, ['in-progress', 'delayed'], true) => 'high',
            $budget >= 40000 => 'high',
            $budget >= 12000 => 'medium',
            default => 'low',
        };
    }

    /** A day per $2.5k of budget, floored at one day and capped at three weeks. */
    private function hoursFor(Job $job): float
    {
        $budget = (float) ($job->budget ?? 0);

        return (float) max(8, min(120, round($budget / 2500) * 8 ?: 8));
    }

    /**
     * Books shifts for work that is already under way, across the current week.
     *
     * Only jobs at `in-progress` or `scheduled` get a crew, which leaves the
     * planning and draft work in the unassigned queue where it belongs.
     */
    private function bookShifts(): void
    {
        $crewLeads = TeamMember::query()
            ->whereIn('role', ['Master Electrician', 'Journeyman Electrician', 'Site Supervisor'])
            ->orderBy('id')
            ->get();

        if ($crewLeads->isEmpty()) {
            return;
        }

        $author = User::query()->orderBy('id')->first();

        $jobs = Job::query()
            ->active()
            ->whereIn('status', ['in-progress', 'scheduled'])
            ->whereDoesntHave('crewShifts')
            ->orderBy('id')
            ->take(6)
            ->get();

        // Monday of the current week: the calendar opens on today, so the shifts
        // have to land in the window the user actually sees first.
        $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);

        foreach ($jobs->values() as $index => $job) {
            $lead = $crewLeads[$index % $crewLeads->count()];
            $crew = self::CREWS[$index % count(self::CREWS)];

            // Staggered starts so the grid shows a realistic spread rather than
            // every job beginning on Monday.
            $firstDay = $monday->copy()->addDays($index % 3);
            $days = min(5, max(1, (int) ceil(((float) ($job->estimated_hours ?? 24)) / 8)));

            for ($day = 0; $day < $days; $day++) {
                $date = $firstDay->copy()->addDays($day);

                // Crews do not work weekends; skip rather than shift, so a job's
                // booked days stay on the working week.
                if ($date->isWeekend()) {
                    continue;
                }

                CrewShift::create([
                    'job_id' => $job->id,
                    'team_member_id' => $lead->id,
                    'created_by' => $author?->id,
                    'crew' => $crew,
                    'scheduled_date' => $date->toDateString(),
                    'start_time' => $index % 2 === 0 ? '08:00:00' : '13:00:00',
                    'duration_hours' => $index % 2 === 0 ? 8 : 4,
                    'status' => $job->status === 'in-progress'
                        ? CrewShift::STATUS_CONFIRMED
                        : CrewShift::STATUS_SCHEDULED,
                ]);
            }
        }
    }
}
