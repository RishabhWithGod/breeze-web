<?php

namespace App\Services\Takeoff;

use App\Models\JobTask;
use App\Models\Project;
use Illuminate\Http\Request;

/**
 * The takeoff a person is part-way through, so they can leave and come back.
 *
 * The whole run — open a client, upload their drawing, wait on the analysis,
 * review the symbols, price the estimate, raise the job, break it into tasks —
 * takes several screens and often several days, and in the middle of it someone
 * goes to look at an invoice. Without this, finding the way back means
 * remembering which client it was and which screen it had reached.
 *
 * Only the client's id is remembered. Where to resume is worked out from the
 * client's own records every time, so the link cannot go stale: sign the review
 * off from somewhere else and the button moves on with it.
 */
class TakeoffFlow
{
    private const KEY = 'takeoff_flow_project_id';

    /** Called by every screen that is a step of the flow. */
    public function remember(Project $client): void
    {
        session([self::KEY => $client->id]);
    }

    /** The flow is over, or the person put the reminder away. */
    public function forget(): void
    {
        session()->forget(self::KEY);
    }

    /**
     * Where to pick the flow up, or null when there is nothing to pick up.
     *
     * Null also while the person is already on one of the flow's own screens:
     * a button offering to take you where you are is noise, and the roadmap at
     * the top of those screens already says where you are.
     *
     * @return array{resumeUrl: string, stage: string, projectName: string}|null
     */
    public function current(Request $request): ?array
    {
        return $this->pending($request, ignoreCurrentScreen: false);
    }

    /**
     * The same thing, answered even on the flow's own screens.
     *
     * Used where the question is "is something already on the go" rather than
     * "should a button be drawn" — every screen that can start a takeoff off
     * asks this, so nobody forks the flow without being told.
     *
     * `$exceptClientId` is the client that screen is already working on: an
     * upload screen opened for the very client being resumed is the resume,
     * not a second start, and warning there would be nonsense.
     *
     * @return array{resumeUrl: string, stage: string, projectName: string}|null
     */
    public function inProgress(Request $request, ?int $exceptClientId = null): ?array
    {
        if ($exceptClientId !== null && session(self::KEY) === $exceptClientId) {
            return null;
        }

        return $this->pending($request, ignoreCurrentScreen: true);
    }

    /** @return array{resumeUrl: string, stage: string, projectName: string}|null */
    private function pending(Request $request, bool $ignoreCurrentScreen): ?array
    {
        $id = session(self::KEY);

        if ($id === null || (! $ignoreCurrentScreen && $this->isOnAFlowScreen($request))) {
            return null;
        }

        $client = Project::find($id);

        // The client was deleted. Nothing to return to.
        if ($client === null) {
            $this->forget();

            return null;
        }

        $step = $this->stepFor($client);

        if ($step === null) {
            // Finished: the job exists and its work is laid out.
            $this->forget();

            return null;
        }

        return [...$step, 'projectName' => $client->name];
    }

    /**
     * The step this client's takeoff is up to, read off their records rather
     * than off wherever the person happened to be standing.
     *
     * @return array{resumeUrl: string, stage: string}|null
     */
    private function stepFor(Project $client): ?array
    {
        $result = $client->aiResults()->latest('id')->first();

        /*
         * Read back to front: a takeoff that has reached the engine is past
         * uploading, whatever the client's upload rows say. Only when nothing
         * has come back does the question "has a drawing been sent at all"
         * decide between waiting on the analysis and starting one.
         */
        if ($result === null) {
            return $client->uploads()->exists()
                ? [
                    // Still running, or the run failed — the processing screen
                    // is where that is said and where it is resubmitted.
                    'resumeUrl' => route('processing.show', $client),
                    'stage' => 'Analysis',
                ]
                : [
                    'resumeUrl' => route('uploads.create', ['project' => $client->id]),
                    'stage' => 'Upload',
                ];
        }

        if (! $result->isFinalised()) {
            return [
                'resumeUrl' => route('reviews.show', $result),
                'stage' => 'Review',
            ];
        }

        if ($result->estimate_id === null) {
            return [
                'resumeUrl' => route('finals.show', $result),
                'stage' => 'Estimate',
            ];
        }

        if ($result->work_job_id === null) {
            return [
                'resumeUrl' => route('estimates.show', ['estimate' => $result->estimate_id, 'flow' => 1]),
                'stage' => 'Job',
            ];
        }

        $hasTasks = JobTask::where('job_id', $result->work_job_id)->exists();

        return $hasTasks ? null : [
            'resumeUrl' => route('jobs.tasks.setup', $result->work_job_id),
            'stage' => 'Tasks',
        ];
    }

    /** The screens that are the flow, by route name. */
    private function isOnAFlowScreen(Request $request): bool
    {
        $name = $request->route()?->getName();

        if ($name === null) {
            return false;
        }

        return in_array($name, [
            'uploads.create',
            'processing.show',
            'reviews.show',
            'finals.show',
            'jobs.tasks.setup',
        ], true)
            // The estimate is only a step of the flow when it says it is.
            || ($name === 'estimates.show' && $request->boolean('flow'));
    }
}
