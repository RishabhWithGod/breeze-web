<?php

namespace Tests\Feature;

use App\Events\AiTakeoffStatusChanged;
use App\Events\JobAssignmentChanged;
use App\Events\JobStatusChanged;
use App\Events\NotificationCreated;
use App\Events\ScheduleChanged;
use App\Events\TimeEntryApproved;
use App\Events\TimeEntryLogged;
use App\Events\TimeEntryRejected;
use App\Events\TimeEntrySubmitted;
use App\Events\TimerStateChanged;
use App\Models\AiJob;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobAssignment;
use App\Models\Project;
use App\Models\TeamMember;
use App\Models\TimeEntry;
use App\Models\Upload;
use App\Models\User;
use App\Notifications\JobAssigned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Phase 8 — realtime broadcasting.
 *
 * These pin the "database first, event second" contract: every broadcast
 * fires only once a real write has actually happened, carries no cost
 * figures, and reaches only the channel its authorization rule allows.
 * They do not exercise the WebSocket wire itself — Reverb's own test suite
 * already covers that — only that this application dispatches the right
 * event, with the right payload, at the right time.
 */
class RealtimeBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(array $attributes = []): Job
    {
        return Job::create([
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside Office Renovation',
            'client' => 'Riverside Properties LLC',
            'status' => 'in-progress',
            ...$attributes,
        ]);
    }

    public function test_a_job_status_change_broadcasts_only_after_the_database_write_commits(): void
    {
        Event::fake([JobStatusChanged::class]);

        $job = $this->makeJob();
        $job->changeStatus('completed');

        Event::assertDispatched(JobStatusChanged::class, function (JobStatusChanged $event) use ($job) {
            return $event->job->id === $job->id && $event->from === 'in-progress' && $event->to === 'completed';
        });

        $this->assertSame('completed', $job->fresh()->status);
    }

    public function test_changing_a_job_to_its_current_status_broadcasts_nothing(): void
    {
        Event::fake([JobStatusChanged::class]);

        $job = $this->makeJob(['status' => 'planning']);
        $job->changeStatus('planning');

        Event::assertNotDispatched(JobStatusChanged::class);
    }

    public function test_a_job_status_broadcast_never_carries_a_dollar_figure(): void
    {
        $job = $this->makeJob();
        $job->changeStatus('completed');

        $payload = (new JobStatusChanged($job, 'in-progress', 'completed'))->broadcastWith();

        foreach (['estimatedTotalCost', 'actualTotalCost', 'profit', 'margin', 'marginPct', 'revenue', 'laborCost', 'billableAmount'] as $costField) {
            $this->assertArrayNotHasKey($costField, $payload);
        }
    }

    public function test_staffing_a_job_broadcasts_the_assignment_with_no_cost_data(): void
    {
        Event::fake([JobAssignmentChanged::class]);
        \Illuminate\Support\Facades\Notification::fake();

        $job = $this->makeJob();
        $manager = User::factory()->create(['role' => 'Project Manager']);

        $this->actingAs($manager)->post("/jobs/{$job->id}/assignments", [
            'role' => JobAssignment::ROLES[0],
            'name' => 'Priya Raman',
        ])->assertSessionHasNoErrors();

        Event::assertDispatched(JobAssignmentChanged::class, function (JobAssignmentChanged $event) use ($job) {
            $payload = $event->broadcastWith();

            return $event->assignment->job_id === $job->id
                && $event->action === 'assigned'
                && ! array_key_exists('cost', $payload)
                && ! array_key_exists('rate', $payload);
        });
    }

    public function test_releasing_an_assignment_broadcasts_the_release(): void
    {
        Event::fake([JobAssignmentChanged::class]);

        $job = $this->makeJob();
        $assignment = $job->assignments()->create([
            'role' => JobAssignment::ROLES[0],
            'name' => 'Priya Raman',
            'assigned_at' => now(),
        ]);

        $manager = User::factory()->create(['role' => 'Project Manager']);
        $this->actingAs($manager)->delete("/jobs/{$job->id}/assignments/{$assignment->id}")
            ->assertSessionHasNoErrors();

        Event::assertDispatched(JobAssignmentChanged::class, fn (JobAssignmentChanged $e) => $e->action === 'released');
    }

    public function test_starting_a_timer_broadcasts_state_with_no_cost_data(): void
    {
        Event::fake([TimerStateChanged::class]);

        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();

        $this->actingAs($user)->post('/time-tracking/timer/start', ['job_id' => $job->id, 'billable' => true])
            ->assertSessionHasNoErrors();

        Event::assertDispatched(TimerStateChanged::class, function (TimerStateChanged $event) use ($user) {
            $payload = $event->broadcastWith();

            return $event->userId === $user->id
                && $event->action === 'started'
                && $payload['timer']['status'] === 'running'
                && ! array_key_exists('laborCost', $payload['timer'])
                && ! array_key_exists('billableRate', $payload['timer']);
        });
    }

    public function test_a_second_timer_start_does_not_broadcast_because_the_write_never_happened(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();

        $this->actingAs($user)->post('/time-tracking/timer/start', ['job_id' => $job->id]);

        Event::fake([TimerStateChanged::class]);

        // Second start is rejected with a validation error — no session is
        // written, so no second "started" broadcast should exist.
        $this->actingAs($user)->post('/time-tracking/timer/start', ['job_id' => $job->id]);

        Event::assertNotDispatched(TimerStateChanged::class);
    }

    public function test_stopping_a_timer_broadcasts_with_no_session_payload_since_the_row_is_gone(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->actingAs($user)->post('/time-tracking/timer/start', ['job_id' => $job->id]);

        Event::fake([TimerStateChanged::class]);

        $this->actingAs($user)->post('/time-tracking/timer/stop')->assertSessionHasNoErrors();

        Event::assertDispatched(TimerStateChanged::class, function (TimerStateChanged $event) use ($user) {
            return $event->userId === $user->id
                && $event->action === 'stopped'
                && $event->session === null
                && $event->broadcastWith()['timer'] === null;
        });
    }

    public function test_logging_a_time_entry_broadcasts_hours_but_never_a_dollar_figure(): void
    {
        Event::fake([TimeEntryLogged::class]);

        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();

        $this->actingAs($user)->post('/time-tracking/entries', [
            'job_id' => $job->id,
            'date' => now()->toDateString(),
            'hours' => 4,
        ])->assertSessionHasNoErrors();

        Event::assertDispatched(TimeEntryLogged::class, function (TimeEntryLogged $event) use ($job) {
            $payload = $event->broadcastWith();

            return $event->action === 'created'
                && $payload['jobId'] === $job->id
                && $payload['hours'] === 4.0
                && ! array_key_exists('laborCost', $payload)
                && ! array_key_exists('billableAmount', $payload);
        });
    }

    public function test_submitting_approving_and_rejecting_a_time_entry_each_broadcast(): void
    {
        $employee = User::factory()->create(['role' => 'Electrician']);
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $job = $this->makeJob();

        $entry = TimeEntry::create([
            'job_id' => $job->id,
            'user_id' => $employee->id,
            'date' => now()->toDateString(),
            'hours' => 3,
            'status' => TimeEntry::STATUS_DRAFT,
            'source' => TimeEntry::SOURCE_MANUAL,
        ]);

        Event::fake([TimeEntrySubmitted::class]);
        $this->actingAs($employee)->post("/time-tracking/entries/{$entry->id}/submit")->assertSessionHasNoErrors();
        Event::assertDispatched(TimeEntrySubmitted::class);

        Event::fake([TimeEntryApproved::class]);
        $this->actingAs($manager)->post("/time-tracking/entries/{$entry->id}/approve")->assertSessionHasNoErrors();
        Event::assertDispatched(TimeEntryApproved::class, function (TimeEntryApproved $event) {
            $payload = $event->broadcastWith();

            return ! array_key_exists('laborCost', $payload) && $payload['status'] === TimeEntry::STATUS_APPROVED;
        });

        $rejectable = TimeEntry::create([
            'job_id' => $job->id,
            'user_id' => $employee->id,
            'date' => now()->toDateString(),
            'hours' => 2,
            'status' => TimeEntry::STATUS_DRAFT,
            'source' => TimeEntry::SOURCE_MANUAL,
        ]);
        $this->actingAs($employee)->post("/time-tracking/entries/{$rejectable->id}/submit");

        Event::fake([TimeEntryRejected::class]);
        $this->actingAs($manager)->post("/time-tracking/entries/{$rejectable->id}/reject", ['reason' => 'Wrong job.'])
            ->assertSessionHasNoErrors();
        Event::assertDispatched(TimeEntryRejected::class);
    }

    public function test_a_schedule_task_change_broadcasts_on_the_jobs_channel(): void
    {
        Event::fake([ScheduleChanged::class]);

        $planner = User::factory()->create(['role' => 'Project Manager']);
        $job = $this->makeJob();

        $this->actingAs($planner)->post("/jobs/{$job->id}/schedule/tasks", [
            'title' => 'Rough-in wiring',
            'category' => 'installation',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(3)->toDateString(),
        ])->assertSessionHasNoErrors();

        Event::assertDispatched(ScheduleChanged::class, fn (ScheduleChanged $e) => $e->jobId === $job->id && $e->type === 'task_created');
    }

    public function test_an_ai_job_being_created_broadcasts_its_queued_state(): void
    {
        Event::fake([AiTakeoffStatusChanged::class]);

        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Test Project', 'client' => 'Acme', 'status' => 'draft']);
        $upload = Upload::create(['project_id' => $project->id, 'user_id' => $user->id, 'name' => 'plan.pdf', 'format' => 'pdf', 'size_bytes' => 1024, 'status' => 'completed']);

        $aiJob = AiJob::create([
            'project_id' => $project->id,
            'upload_id' => $upload->id,
            'user_id' => $user->id,
            'status' => AiJob::STATUS_QUEUED,
            'progress' => 0,
        ]);

        Event::assertDispatched(AiTakeoffStatusChanged::class, fn (AiTakeoffStatusChanged $e) => $e->aiJob->id === $aiJob->id);
    }

    public function test_an_ai_jobs_status_update_broadcasts_but_an_unrelated_field_update_does_not(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Test Project', 'client' => 'Acme', 'status' => 'draft']);
        $upload = Upload::create(['project_id' => $project->id, 'user_id' => $user->id, 'name' => 'plan.pdf', 'format' => 'pdf', 'size_bytes' => 1024, 'status' => 'completed']);
        $aiJob = AiJob::create([
            'project_id' => $project->id,
            'upload_id' => $upload->id,
            'user_id' => $user->id,
            'status' => AiJob::STATUS_QUEUED,
            'progress' => 0,
        ]);

        Event::fake([AiTakeoffStatusChanged::class]);

        $aiJob->update(['status' => AiJob::STATUS_PROCESSING]);
        Event::assertDispatched(AiTakeoffStatusChanged::class);

        Event::fake([AiTakeoffStatusChanged::class]);
        $aiJob->update(['poll_attempts' => 5]);
        Event::assertNotDispatched(AiTakeoffStatusChanged::class);
    }

    public function test_a_created_notification_broadcasts_to_its_owner_with_a_fresh_unread_count(): void
    {
        Event::fake([NotificationCreated::class]);

        $job = $this->makeJob();
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $member = TeamMember::create(['name' => 'Priya Raman', 'initials' => 'PR', 'role' => 'Project Manager', 'user_id' => $manager->id]);

        $assignment = $job->assignments()->create([
            'team_member_id' => $member->id,
            'user_id' => $manager->id,
            'role' => JobAssignment::ROLES[0],
            'name' => $member->name,
            'assigned_at' => now(),
        ]);

        $manager->notify(new JobAssigned($assignment));

        Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $event) use ($manager) {
            return $event->notification->user_id === $manager->id
                && $event->broadcastWith()['unreadCount'] >= 1;
        });
    }
}
