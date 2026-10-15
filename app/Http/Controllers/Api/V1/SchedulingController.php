<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\CrewShift;
use App\Models\Job;
use App\Services\Scheduling\CrewBoard;
use App\Support\Ownership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

    /**
     * The crew calendar — mobile's counterpart to web's own
     * `SchedulingController::calendar()`. The same `CrewBoard` web draws, so
     * the two never disagree about which job sits on which crew's day.
     */
    public function calendar(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'view' => ['nullable', Rule::in(['month', 'week', 'day'])],
            'date' => ['nullable', 'date'],
        ]);

        $anchor = isset($filters['date'])
            ? Carbon::parse($filters['date'])->startOfDay()
            : Carbon::today();

        return $this->ok([
            'anchor' => $anchor->toDateString(),
            'today' => Carbon::today()->toDateString(),
            'board' => app(CrewBoard::class)->build($request->user(), $filters['view'] ?? 'month', $anchor),
        ]);
    }

    /**
     * Jobs that still need a crew — the same queue as web's own
     * `SchedulingController::unassigned()`: raised but not yet broken into
     * tasks, with the same search, type tab and sort, and the per-type
     * counts its tabs show.
     */
    public function unassigned(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(['all', ...Job::TYPES])],
            'sort' => ['nullable', Rule::in(Job::SCHEDULING_SORTS)],
        ]);

        $user = $request->user();
        $type = $filters['type'] ?? 'all';

        $jobs = Job::query()
            ->ownedBy($user)
            ->with(['team:id,name', 'tasks.foreman', 'tasks.supervisor', 'foreman'])
            ->withoutCrew()
            ->search($filters['search'] ?? null)
            ->when($type !== 'all', fn ($query) => $query->where('job_type', $type))
            ->sortedForScheduling($filters['sort'] ?? 'start-desc')
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        // Counted without the type filter, so the tabs keep the whole queue's shape.
        $counts = Job::query()
            ->ownedBy($user)
            ->reorder()
            ->withoutCrew()
            ->search($filters['search'] ?? null)
            ->selectRaw('job_type, count(*) as total')
            ->groupBy('job_type')
            ->pluck('total', 'job_type');

        return $this->ok([
            'jobs' => $jobs->getCollection()->map(fn (Job $job) => [
                'id' => $job->id,
                'name' => $job->name,
                'client' => $job->client,
                'location' => $job->location,
                'jobType' => $job->job_type,
                'priority' => $job->priority ?? 'medium',
                'startDate' => $job->start_date?->toDateString(),
                'endDate' => $job->end_date?->toDateString(),
                'createdAt' => $job->created_at?->toDateString(),
                'estimatedHours' => $job->estimated_hours === null ? null : (float) $job->estimated_hours,
                'value' => $job->budget === null ? null : (float) $job->budget,
                'requiredSkills' => $job->required_skills ?? [],
                'teamName' => $job->team?->name,
                'foremen' => $job->assignedForemen(),
                'supervisors' => $job->assignedSupervisors(),
            ])->all(),
            'counts' => [
                'all' => (int) $counts->sum(),
                'residential' => (int) ($counts['residential'] ?? 0),
                'commercial' => (int) ($counts['commercial'] ?? 0),
                'industrial' => (int) ($counts['industrial'] ?? 0),
            ],
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
        abort_unless(Ownership::owns($request->user(), $job->user_id), 403);

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
