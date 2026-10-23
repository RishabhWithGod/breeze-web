<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Controllers\JobScheduleController;
use App\Http\Controllers\JobTaskController;
use App\Models\Job;
use App\Models\JobTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The manager's Job Schedule screen on the phone — web's `JobSchedule` page: window, progress,
 * tasks in order, timeline, calendar, workload, crew shifts, dependencies and the schedule's own
 * settings. Reads and writes go through the same web controllers, so the rules cannot drift.
 */
class JobScheduleBoardController extends Controller
{
    use ApiResponses;

    public function show(Request $request, Job $job): JsonResponse
    {
        return $this->ok(app(JobScheduleController::class)->board($request, $job));
    }

    /** The schedule's own settings: window, working hours and days, holidays, status, notes. */
    public function update(Request $request, Job $job): JsonResponse
    {
        app(JobScheduleController::class)->update($request, $job);

        return $this->ok(null, 'The schedule was updated.');
    }

    /** Moves tasks into a new order (`order`: task ids, first to last). */
    public function reorder(Request $request, Job $job): JsonResponse
    {
        app(JobTaskController::class)->reorder($request, $job);

        return $this->ok(null, 'Tasks reordered.');
    }

    /* Task actions on the schedule — each is web's own `JobTaskController` action. */

    public function delay(Request $request, JobTask $task): JsonResponse
    {
        app(JobTaskController::class)->delay($request, $task);

        return $this->ok(null, 'The task was delayed.');
    }

    public function move(Request $request, JobTask $task): JsonResponse
    {
        app(JobTaskController::class)->move($request, $task);

        return $this->ok(null, 'The task was moved.');
    }

    public function assign(Request $request, JobTask $task): JsonResponse
    {
        app(JobTaskController::class)->assign($request, $task);

        return $this->ok(null, 'Crew member assigned.');
    }

    public function unassign(Request $request, JobTask $task, int $assignment): JsonResponse
    {
        app(JobTaskController::class)->unassign($request, $task, $assignment);

        return $this->ok(null, 'Crew member removed.');
    }

    public function addDependency(Request $request, JobTask $task): JsonResponse
    {
        app(JobTaskController::class)->addDependency($request, $task);

        return $this->ok(null, 'Dependency added.');
    }

    public function removeDependency(Request $request, JobTask $task, int $dependency): JsonResponse
    {
        app(JobTaskController::class)->removeDependency($request, $task, $dependency);

        return $this->ok(null, 'Dependency removed.');
    }
}
