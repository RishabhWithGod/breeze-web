<?php

namespace Tests\Feature\Api;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobTask;
use App\Models\User;
use App\Services\Scheduling\ScheduleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The two-step job completion workflow: a foreman/electrician closing every
 * task only ever gets a job as far as ready-for-review; only a supervisor's
 * own tap actually completes it, and only once it's ready. A supervisor
 * reopening a task sends it back to the crew. Once actually completed, the
 * job is locked — no further task, checklist, note, photo, or timer writes
 * from anyone, in the app.
 */
class MobileJobCompletionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function makeJob(array $attributes = []): Job
    {
        return Job::create([
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside Office Renovation',
            'client' => 'Riverside Properties LLC',
            'job_type' => 'commercial',
            'status' => 'in-progress',
            ...$attributes,
        ]);
    }

    private function makeMobileForeman(string $name = 'Robert'): array
    {
        $user = User::factory()->create([
            'name' => $name,
            'role' => 'Foreman',
            'registration_source' => User::SOURCE_MOBILE,
            'status' => User::STATUS_ACTIVE,
        ]);
        $foreman = new Foreman(['name' => $name, 'initials' => 'RB', 'role' => Foreman::ROLE_FOREMAN]);
        $foreman->user_id = $user->id;
        $foreman->save();

        return [$user, $foreman];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /**
     * A supervisor test double for BOTH authority checks this workflow
     * touches, which key off two different roles: `Api\V1\JobController
     * ::changeStatus()`'s crew/supervisor split reads `Foreman::role` (the
     * crew register), while `JobSchedulePolicy::updateTask()` — what a task
     * reopen or a checklist uncheck requires — reads `User::role` (the web
     * job-title vocabulary). A real site supervisor account carries both;
     * a test double needs both set to actually exercise either path.
     */
    private function makeMobileSupervisor(string $name = 'Dana'): array
    {
        [$user, $foreman] = $this->makeMobileForeman($name);
        $user->update(['role' => 'Site Supervisor']);
        $foreman->update(['role' => Foreman::ROLE_SUPERVISOR]);

        return [$user, $foreman];
    }

    /** A job with every task closed and assigned to one foreman, ready for that foreman to submit it. */
    private function jobWithEveryTaskDone(): array
    {
        [$foremanUser, $foreman] = $this->makeMobileForeman();
        $job = $this->makeJob(['foreman_id' => $foreman->id]);

        $schedule = app(ScheduleBuilder::class)->build(
            $job,
            User::factory()->create(['role' => 'Project Manager']),
            withTasks: true,
        );
        $tasks = $schedule->tasks()->orderBy('position')->get();
        $this->assertGreaterThan(0, $tasks->count());

        foreach ($tasks as $task) {
            $task->foreman_id = $foreman->id;
            $task->status = JobTask::STATUS_COMPLETED;
            $task->completed_at = now();
            $task->save();
        }

        return [$foremanUser, $foreman, $job, $tasks];
    }

    /** One labor checklist line grouped into a task — same shape `MobileJobsAndTasksTest` uses. */
    private function makeEstimateItemOnTask(JobTask $task, bool $completed = true): EstimateItem
    {
        $estimate = Estimate::create([
            'number' => 'EST-'.random_int(1000, 9999),
            'client' => 'Test Client',
            'project' => 'Test Project',
            'issued_on' => now()->toDateString(),
            'amount' => 100,
            'status' => 'draft',
        ]);

        return EstimateItem::create([
            'estimate_id' => $estimate->id,
            'job_task_id' => $task->id,
            'category' => EstimateItem::CATEGORY_LABOR,
            'description' => 'Run conduit',
            'unit' => 'ea',
            'quantity' => 1,
            'unit_cost' => 5,
            'completed_at' => $completed ? now() : null,
        ]);
    }

    private function completeAsRequest(Job $job, User $user): \Illuminate\Testing\TestResponse
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'completed']);
        auth()->forgetGuards();

        return $response;
    }

    /** A supervisor's targeted sign-off on one foreman's own portion — the
     *  step that has to happen, per foreman, before `completeAsRequest()`'s
     *  final close-out can succeed. */
    private function approveForemanAsRequest(Job $job, User $supervisorUser, Foreman $foreman): \Illuminate\Testing\TestResponse
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($supervisorUser))
            ->postJson("/api/v1/jobs/{$job->id}/foremen/{$foreman->id}/approve");
        auth()->forgetGuards();

        return $response;
    }

    private function startAsRequest(Job $job, User $user): \Illuminate\Testing\TestResponse
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'in-progress']);
        auth()->forgetGuards();

        return $response;
    }

    /* -------------------------------------------------------- crew start */

    public function test_one_foremans_start_does_not_start_another_foremans_own_work(): void
    {
        [$foremanAUser, $foremanA] = $this->makeMobileForeman('Robert');
        [$foremanBUser, $foremanB] = $this->makeMobileForeman('Priya');
        $job = $this->makeJob(['foreman_id' => $foremanA->id, 'status' => 'scheduled']);

        $schedule = app(\App\Services\Scheduling\ScheduleBuilder::class)->build(
            $job,
            User::factory()->create(['role' => 'Project Manager']),
            withTasks: true,
        );
        $tasks = $schedule->tasks()->orderBy('position')->get();
        $this->assertGreaterThanOrEqual(2, $tasks->count());
        $half = intdiv($tasks->count(), 2);
        foreach ($tasks as $i => $task) {
            $task->update(['foreman_id' => $i < $half ? $foremanA->id : $foremanB->id]);
        }

        // Foreman A starts the job — the shared job-wide status does flip,
        // since other gates (checklist, notes, timer) only ever understood
        // one "has work begun" flag for the whole job.
        $this->startAsRequest($job, $foremanAUser)->assertOk();
        $job->refresh();
        $this->assertSame('in-progress', $job->status);

        // A's own start is recorded...
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanAUser))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.myStartedAt', fn ($v) => $v !== null);
        auth()->forgetGuards();

        // ...but B's own start is untouched — the job being in-progress
        // job-wide is not the same as B having tapped Start themselves.
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanBUser))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.myStartedAt', null);
        auth()->forgetGuards();

        // B can still start their own — no error just because the job is
        // already in-progress from A's own tap.
        $this->startAsRequest($job, $foremanBUser)
            ->assertOk()
            ->assertJsonPath('message', 'Status unchanged.');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanBUser))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.myStartedAt', fn ($v) => $v !== null);
        auth()->forgetGuards();
    }

    /* ---------------------------------------------------- crew submission */

    public function test_a_foreman_completing_every_task_marks_the_job_ready_for_review_not_completed(): void
    {
        [$foremanUser, , $job] = $this->jobWithEveryTaskDone();

        $this->completeAsRequest($job, $foremanUser)
            ->assertOk()
            ->assertJsonPath('data.readyForReviewAt', fn ($v) => $v !== null);

        $job->refresh();
        $this->assertSame('in-progress', $job->status);
        $this->assertNotNull($job->ready_for_review_at);
    }

    public function test_the_job_detail_response_reports_readyForReviewAt(): void
    {
        [$foremanUser, , $job] = $this->jobWithEveryTaskDone();
        $this->completeAsRequest($job, $foremanUser)->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanUser))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.readyForReviewAt', fn ($v) => $v !== null);
    }

    /* ------------------------------------------------- supervisor sign-off */

    public function test_a_supervisor_cannot_complete_a_job_that_is_not_yet_ready_for_review(): void
    {
        [, $foreman, $job, $tasks] = $this->jobWithEveryTaskDone();

        [$supervisorUser, $supervisor] = $this->makeMobileSupervisor('Dana');
        $tasks->first()->update(['supervisor_id' => $supervisor->id]);

        // Every task is closed, but the crew hasn't tapped Complete yet —
        // the supervisor still has nothing to review.
        $this->completeAsRequest($job, $supervisorUser)
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'not_ready_for_review');

        $this->assertSame('in-progress', $job->fresh()->status);
    }

    public function test_a_supervisor_completes_a_job_once_it_is_ready_for_review(): void
    {
        [$foremanUser, $foreman, $job, $tasks] = $this->jobWithEveryTaskDone();

        [$supervisorUser, $supervisor] = $this->makeMobileSupervisor('Dana');
        $tasks->first()->update(['supervisor_id' => $supervisor->id]);

        $this->completeAsRequest($job, $foremanUser)->assertOk();

        // The targeted, per-foreman approve is what actually clears the
        // "not ready" gate below — a single foreman on the job, but the
        // rule (approve first, close second) is the same regardless.
        $this->approveForemanAsRequest($job, $supervisorUser, $foreman)
            ->assertOk()
            ->assertJsonPath('data.fullyApproved', true);

        $this->completeAsRequest($job, $supervisorUser)
            ->assertOk()
            ->assertJsonPath('data.to', 'completed');

        $this->assertSame('completed', $job->fresh()->status);
    }

    public function test_approving_a_foreman_who_has_not_submitted_is_refused(): void
    {
        [, $foreman, $job, $tasks] = $this->jobWithEveryTaskDone();

        [$supervisorUser, $supervisor] = $this->makeMobileSupervisor('Dana');
        $tasks->first()->update(['supervisor_id' => $supervisor->id]);

        // Every task is closed, but the foreman hasn't tapped Complete yet.
        $this->approveForemanAsRequest($job, $supervisorUser, $foreman)
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'not_ready_for_review');
    }

    public function test_only_a_supervisor_can_approve_a_foreman(): void
    {
        [$foremanUser, $foreman, $job] = $this->jobWithEveryTaskDone();
        $this->completeAsRequest($job, $foremanUser)->assertOk();

        // A foreman — even the one who submitted — has no authority to
        // approve their own work; only the supervisor role can.
        $this->approveForemanAsRequest($job, $foremanUser, $foreman)->assertStatus(403);
    }

    public function test_approving_one_foreman_never_touches_another_foremans_own_review(): void
    {
        [$foremanAUser, $foremanA] = $this->makeMobileForeman('Robert');
        [$foremanBUser, $foremanB] = $this->makeMobileForeman('Priya');
        $job = $this->makeJob(['foreman_id' => $foremanA->id]);

        $schedule = app(\App\Services\Scheduling\ScheduleBuilder::class)->build(
            $job,
            User::factory()->create(['role' => 'Project Manager']),
            withTasks: true,
        );
        $tasks = $schedule->tasks()->orderBy('position')->get();
        $this->assertGreaterThanOrEqual(2, $tasks->count());

        $half = intdiv($tasks->count(), 2);
        foreach ($tasks as $i => $task) {
            $task->foreman_id = $i < $half ? $foremanA->id : $foremanB->id;
            $task->status = JobTask::STATUS_COMPLETED;
            $task->completed_at = now();
            $task->save();
        }

        [$supervisorUser, $supervisor] = $this->makeMobileSupervisor('Dana');
        $tasks->first()->update(['supervisor_id' => $supervisor->id]);

        $this->completeAsRequest($job, $foremanAUser)->assertOk();
        $this->completeAsRequest($job, $foremanBUser)->assertOk();

        // Approving A specifically must leave B's own row untouched — not
        // implicitly approved just because both happened to be ready.
        $this->approveForemanAsRequest($job, $supervisorUser, $foremanA)->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanBUser))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.myApprovedAt', null)
            ->assertJsonPath('data.myReadyForReviewAt', fn ($v) => $v !== null);
        auth()->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanAUser))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.myApprovedAt', fn ($v) => $v !== null);
        auth()->forgetGuards();

        // The job LIST endpoint — what the app's own job list/home screen
        // actually reads, not just the detail screen — has to carry this
        // same per-foreman state too, or the list keeps showing "in
        // progress" for a foreman whose own portion the detail screen
        // already reports as approved.
        $listResponse = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanAUser))
            ->getJson('/api/v1/jobs')
            ->assertOk();
        auth()->forgetGuards();
        $listedJob = collect($listResponse->json('data.jobs'))->firstWhere('id', $job->id);
        $this->assertNotNull($listedJob);
        $this->assertNotNull($listedJob['myApprovedAt']);

        $listResponseB = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanBUser))
            ->getJson('/api/v1/jobs')
            ->assertOk();
        auth()->forgetGuards();
        $listedJobB = collect($listResponseB->json('data.jobs'))->firstWhere('id', $job->id);
        $this->assertNotNull($listedJobB);
        $this->assertNull($listedJobB['myApprovedAt']);

        $this->assertNotSame('completed', $job->fresh()->status);
    }

    public function test_crew_time_reports_approved_for_a_supervisor_rather_than_the_last_timer_status(): void
    {
        [$foremanUser, $foreman, $job, $tasks] = $this->jobWithEveryTaskDone();

        [$supervisorUser, $supervisor] = $this->makeMobileSupervisor('Dana');
        $tasks->first()->update(['supervisor_id' => $supervisor->id]);

        // A paused session left over from before completion — exactly the
        // stale-looking state a supervisor's crew list must not surface as
        // this foreman's actual status once they're done.
        \App\Models\TimerSession::create([
            'user_id' => $foremanUser->id,
            'job_id' => $job->id,
            'team_member_id' => null,
            'started_at' => now()->subHour(),
            'accumulated_seconds' => 1800,
            'status' => 'paused',
            'billable' => true,
        ]);

        $this->completeAsRequest($job, $foremanUser)->assertOk();
        $this->approveForemanAsRequest($job, $supervisorUser, $foreman)->assertOk();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($supervisorUser))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk();

        $entry = collect($response->json('data.crewTime'))->firstWhere('foremanId', $foreman->id);
        $this->assertNotNull($entry);
        $this->assertNotNull($entry['approvedAt']);
    }

    /* --------------------------------------- crew locked out while reviewing */

    public function test_a_foreman_cannot_complete_a_task_while_the_job_is_ready_for_review(): void
    {
        [$foremanUser, , $job, $tasks] = $this->jobWithEveryTaskDone();
        $this->completeAsRequest($job, $foremanUser)->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanUser))
            ->postJson("/api/v1/tasks/{$tasks->first()->id}/complete")
            ->assertStatus(409);
    }

    public function test_a_foreman_cannot_update_progress_while_the_job_is_ready_for_review(): void
    {
        [$foremanUser, , $job, $tasks] = $this->jobWithEveryTaskDone();
        $this->completeAsRequest($job, $foremanUser)->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanUser))
            ->patchJson("/api/v1/tasks/{$tasks->first()->id}/progress", ['completion_pct' => 50])
            ->assertStatus(409);
    }

    public function test_a_foreman_cannot_check_off_a_checklist_item_while_the_job_is_ready_for_review(): void
    {
        [$foremanUser, , $job, $tasks] = $this->jobWithEveryTaskDone();
        $item = $this->makeEstimateItemOnTask($tasks->first(), completed: false);

        $this->completeAsRequest($job, $foremanUser)->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanUser))
            ->patchJson("/api/v1/estimate-items/{$item->id}/completion", ['completed' => true])
            ->assertStatus(409);
    }

    public function test_a_foreman_cannot_add_a_note_while_the_job_is_ready_for_review(): void
    {
        [$foremanUser, , $job, $tasks] = $this->jobWithEveryTaskDone();
        $this->completeAsRequest($job, $foremanUser)->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanUser))
            ->postJson("/api/v1/tasks/{$tasks->first()->id}/notes", ['body' => 'Too soon.'])
            ->assertStatus(409);
    }

    public function test_a_foreman_cannot_upload_a_photo_while_the_job_is_ready_for_review(): void
    {
        [$foremanUser, , $job, $tasks] = $this->jobWithEveryTaskDone();
        $this->completeAsRequest($job, $foremanUser)->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanUser))
            ->postJson("/api/v1/tasks/{$tasks->first()->id}/attachments", [
                'file' => UploadedFile::fake()->image('photo.jpg'),
            ])
            ->assertStatus(409);
    }

    public function test_a_foreman_cannot_start_a_new_timer_while_the_job_is_ready_for_review(): void
    {
        [$foremanUser, , $job] = $this->jobWithEveryTaskDone();
        $this->completeAsRequest($job, $foremanUser)->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanUser))
            ->postJson('/api/v1/timer/start', ['job_id' => $job->id])
            ->assertStatus(409);
    }

    public function test_a_supervisor_can_still_add_a_note_while_reviewing(): void
    {
        [$foremanUser, , $job, $tasks] = $this->jobWithEveryTaskDone();
        [$supervisorUser, $supervisor] = $this->makeMobileSupervisor('Dana');
        $tasks->first()->update(['supervisor_id' => $supervisor->id]);

        $this->completeAsRequest($job, $foremanUser)->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($supervisorUser))
            ->postJson("/api/v1/tasks/{$tasks->first()->id}/notes", ['body' => 'Looks good, one thing to fix.'])
            ->assertStatus(201);
    }

    public function test_a_supervisor_reopening_a_task_sends_the_job_back_to_the_crew(): void
    {
        [$foremanUser, $foreman, $job, $tasks] = $this->jobWithEveryTaskDone();

        [$supervisorUser, $supervisor] = $this->makeMobileSupervisor('Dana');
        $tasks->first()->update(['supervisor_id' => $supervisor->id]);

        $this->completeAsRequest($job, $foremanUser)->assertOk();
        $this->assertNotNull($job->fresh()->ready_for_review_at);

        // The supervisor reopens one task while reviewing.
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($supervisorUser))
            ->patchJson("/api/v1/tasks/{$tasks->first()->id}/status", ['status' => JobTask::STATUS_IN_PROGRESS])
            ->assertOk();
        auth()->forgetGuards();

        $this->assertNull($job->fresh()->ready_for_review_at);

        // The supervisor still cannot complete it — back to square one.
        $this->completeAsRequest($job, $supervisorUser)
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'not_ready_for_review');

        // The foreman closes the task again and resubmits. `fresh()` first —
        // `$tasks->first()` was fetched before the reopen above changed the
        // row through a different model instance (the HTTP request), so its
        // in-memory `status` is stale; without refetching, Eloquent's dirty
        // check would see `status` as already 'completed' and silently drop
        // it from the update.
        $tasks->first()->fresh()->update(['status' => JobTask::STATUS_COMPLETED, 'completed_at' => now()]);
        $this->completeAsRequest($job, $foremanUser)->assertOk();

        $this->approveForemanAsRequest($job, $supervisorUser, $foreman)->assertOk();
        $this->completeAsRequest($job, $supervisorUser)->assertOk();
        $this->assertSame('completed', $job->fresh()->status);
    }

    public function test_unchecking_a_completed_tasks_checklist_line_also_sends_the_job_back(): void
    {
        [$foremanUser, $foreman, $job] = $this->jobWithEveryTaskDone();

        [$supervisorUser, $supervisor] = $this->makeMobileSupervisor('Dana');

        $task = $job->tasks()->first();
        $task->update(['supervisor_id' => $supervisor->id]);
        $item = $this->makeEstimateItemOnTask($task);

        $this->completeAsRequest($job, $foremanUser)->assertOk();
        $this->assertNotNull($job->fresh()->ready_for_review_at);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($supervisorUser))
            ->patchJson("/api/v1/estimate-items/{$item->id}/completion", ['completed' => false])
            ->assertOk();
        auth()->forgetGuards();

        $this->assertNull($job->fresh()->ready_for_review_at);
    }

    /* --------------------------------------------------------- once locked */

    private function completedJob(): array
    {
        [$foremanUser, $foreman, $job, $tasks] = $this->jobWithEveryTaskDone();

        [$supervisorUser, $supervisor] = $this->makeMobileSupervisor('Dana');
        $tasks->first()->update(['supervisor_id' => $supervisor->id]);

        $this->completeAsRequest($job, $foremanUser)->assertOk();
        $this->approveForemanAsRequest($job, $supervisorUser, $foreman)->assertOk();
        $this->completeAsRequest($job, $supervisorUser)->assertOk();

        return [$foremanUser, $foreman, $job, $tasks];
    }

    public function test_a_completed_job_refuses_any_further_status_change(): void
    {
        [$foremanUser, , $job] = $this->completedJob();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanUser))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'in-progress'])
            ->assertStatus(409);
    }

    public function test_a_completed_job_refuses_a_task_status_change(): void
    {
        [$foremanUser, , , $tasks] = $this->completedJob();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanUser))
            ->patchJson("/api/v1/tasks/{$tasks->first()->id}/progress", ['completion_pct' => 50])
            ->assertStatus(409);
    }

    public function test_a_completed_job_refuses_a_checklist_change(): void
    {
        [$foremanUser, , , $tasks] = $this->completedJob();

        $item = $this->makeEstimateItemOnTask($tasks->first());

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanUser))
            ->patchJson("/api/v1/estimate-items/{$item->id}/completion", ['completed' => false])
            ->assertStatus(409);
    }

    public function test_a_completed_job_refuses_a_new_note(): void
    {
        [$foremanUser, , , $tasks] = $this->completedJob();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanUser))
            ->postJson("/api/v1/tasks/{$tasks->first()->id}/notes", ['body' => 'Too late.'])
            ->assertStatus(409);
    }

    public function test_a_completed_job_refuses_a_new_photo(): void
    {
        [$foremanUser, , , $tasks] = $this->completedJob();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanUser))
            ->postJson("/api/v1/tasks/{$tasks->first()->id}/attachments", [
                'file' => UploadedFile::fake()->image('photo.jpg'),
            ])
            ->assertStatus(409);
    }

    public function test_a_completed_job_refuses_starting_a_new_timer(): void
    {
        [$foremanUser, , $job] = $this->completedJob();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanUser))
            ->postJson('/api/v1/timer/start', ['job_id' => $job->id])
            ->assertStatus(409);
    }
}
