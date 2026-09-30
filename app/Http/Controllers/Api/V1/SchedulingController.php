<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\CrewShift;
use App\Models\Job;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Unassigned Queue and "Book crew" action — mobile's counterpart to
 * web's own `SchedulingController::unassigned()`/`store()`. No crew/member
 * picker: a job's foremen are decided when its work is broken into tasks,
 * and asking again here invited a second, different answer — booking only
 * asks when and for how long, same as web's own `AssignCrewModal`.
 */
class SchedulingController extends Controller
{
    use ApiResponses;

    public function unassigned(Request $request): JsonResponse
    {
        $jobs = Job::query()
            ->ownedBy($request->user())
            ->with(['team:id,name', 'tasks.foreman', 'tasks.supervisor', 'foreman'])
            ->unscheduled()
            ->orderByRaw('start_date is null')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return $this->ok([
            'jobs' => $jobs->getCollection()->map(fn (Job $job) => [
                'id' => $job->id,
                'name' => $job->name,
                'client' => $job->client,
                'location' => $job->location,
                'jobType' => $job->job_type,
                'startDate' => $job->start_date?->toDateString(),
                'endDate' => $job->end_date?->toDateString(),
                'estimatedHours' => $job->estimated_hours === null ? null : (float) $job->estimated_hours,
                'teamName' => $job->team?->name,
                'foremen' => $job->assignedForemen(),
                'supervisors' => $job->assignedSupervisors(),
            ])->all(),
            'meta' => [
                'currentPage' => $jobs->currentPage(),
                'lastPage' => $jobs->lastPage(),
                'perPage' => $jobs->perPage(),
                'total' => $jobs->total(),
            ],
        ]);
    }

    /** Books the job's own crew onto the calendar — mobile's counterpart to web's own `store()`. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'job_id' => ['required', 'integer', 'exists:work_jobs,id'],
            'scheduled_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_hours' => ['required', 'numeric', 'min:0.5', 'max:24'],
            'days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'job_id.required' => 'Pick the job to book.',
            'scheduled_date.required' => 'Pick the day this starts.',
            'start_time.required' => 'Pick a start time.',
            'duration_hours.required' => 'Pick a shift length.',
        ]);

        $job = Job::with('team', 'tasks.foreman', 'foreman')->findOrFail($data['job_id']);
        abort_unless(\App\Support\Ownership::owns($request->user(), $job->user_id), 403);

        $crew = $this->crewLabel($job);
        $start = Carbon::parse($data['scheduled_date'])->startOfDay();
        $days = $data['days'] ?? 1;
        $booked = 0;

        // One transaction: a part-booked job would show on the calendar for
        // some of its days and in the unassigned queue for none of them.
        DB::transaction(function () use ($data, $job, $start, $days, $request, $crew, &$booked) {
            for ($offset = 0, $placed = 0; $placed < $days && $offset < $days + 10; $offset++) {
                $date = $start->copy()->addDays($offset);

                if ($date->isWeekend()) {
                    continue;
                }

                CrewShift::create([
                    'job_id' => $job->id,
                    'created_by' => $request->user()->id,
                    'crew' => $crew,
                    'scheduled_date' => $date->toDateString(),
                    'start_time' => $data['start_time'].':00',
                    'duration_hours' => $data['duration_hours'],
                    'status' => CrewShift::STATUS_SCHEDULED,
                    'notes' => $data['notes'] ?? null,
                ]);

                $placed++;
                $booked++;
            }

            // A booked job is a scheduled job. Work already under way keeps
            // its own status.
            if (in_array($job->status, ['draft', 'planning'], true)) {
                $job->changeStatus('scheduled');
            }

            if ($job->start_date === null) {
                $job->forceFill(['start_date' => $start->toDateString()])->saveQuietly();
            }

            $job->recordActivity(
                'scheduled',
                "Booked {$crew} for {$booked} ".str('day')->plural($booked)." from {$start->format('m/d/Y')}",
                ['crew' => $crew, 'days' => $booked],
            );
        });

        return $this->created([
            'jobId' => $job->id,
            'crew' => $crew,
            'daysBooked' => $booked,
        ], "{$job->name} scheduled — {$crew} booked for {$booked} ".str('day')->plural($booked).'.');
    }

    /** The shift is labelled with whoever is already on the job — never a stranger. */
    private function crewLabel(Job $job): string
    {
        if ($job->team !== null) {
            return $job->team->name;
        }

        $names = array_column($job->assignedForemen(), 'name');

        return $names === [] ? 'Unassigned' : implode(', ', $names);
    }
}
