<?php

namespace Tests\Feature;

use App\Events\TimeEntryApproved;
use App\Events\TimeEntryRejected;
use App\Events\TimeEntrySubmitted;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\JobTask;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The approval lifecycle: draft → submitted → approved/rejected → locked.
 *
 * The rule worth pinning is that an approved entry is never edited in place —
 * a correction locks the original and raises a fresh draft that points back
 * at it — and that approving is what feeds `job_tasks.actual_hours`, the same
 * column Job Costing and the Estimate comparison both read.
 */
class TimeEntryApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = User::factory()->create(['role' => 'Electrician']);
        $this->manager = User::factory()->create(['role' => 'Project Manager']);
    }

    public function test_the_full_lifecycle_from_draft_to_approved(): void
    {
        Event::fake([TimeEntrySubmitted::class, TimeEntryApproved::class]);

        $job = $this->makeJob();
        $task = $this->makeTask($job, estimatedHours: 10);

        $this->actingAs($this->employee)->post('/time-tracking/entries', [
            'job_id' => $job->id,
            'job_task_id' => $task->id,
            'date' => '2026-08-10',
            'hours' => 6,
        ]);
        $entry = TimeEntry::sole();
        $this->assertSame(TimeEntry::STATUS_DRAFT, $entry->status);

        $this->actingAs($this->employee)
            ->post("/time-tracking/entries/{$entry->id}/submit")
            ->assertSessionHasNoErrors();
        $entry->refresh();
        $this->assertSame(TimeEntry::STATUS_SUBMITTED, $entry->status);
        $this->assertNotNull($entry->submitted_at);
        Event::assertDispatched(TimeEntrySubmitted::class);

        // An employee cannot approve their own submission.
        $this->actingAs($this->employee)
            ->post("/time-tracking/entries/{$entry->id}/approve")
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->post("/time-tracking/entries/{$entry->id}/approve")
            ->assertSessionHasNoErrors();
        $entry->refresh();
        $this->assertSame(TimeEntry::STATUS_APPROVED, $entry->status);
        $this->assertSame($this->manager->id, $entry->approved_by);
        Event::assertDispatched(TimeEntryApproved::class);

        // Approving feeds the task's actual hours — the same column Job
        // Costing and the Estimate variance both read.
        $this->assertSame('6.00', $task->fresh()->actual_hours);

        // `activities()` orders newest first, matching the timeline it feeds.
        $this->assertSame(
            ['approved', 'submitted', 'created'],
            $entry->activities()->pluck('type')->filter(fn ($t) => $t !== 'status_changed')->values()->all(),
        );
    }

    public function test_rejecting_requires_a_reason_and_returns_the_entry_to_the_employee(): void
    {
        Event::fake([TimeEntryRejected::class]);

        $job = $this->makeJob();
        $entry = $this->submittedEntry($job);

        $this->actingAs($this->manager)
            ->post("/time-tracking/entries/{$entry->id}/reject")
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->manager)
            ->post("/time-tracking/entries/{$entry->id}/reject", ['reason' => 'Hours look too high for this task.'])
            ->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertSame(TimeEntry::STATUS_REJECTED, $entry->status);
        $this->assertSame('Hours look too high for this task.', $entry->rejection_reason);
        $this->assertTrue($entry->isEditable());
        Event::assertDispatched(TimeEntryRejected::class);
    }

    public function test_correcting_an_approved_entry_locks_the_original_and_raises_a_draft(): void
    {
        $job = $this->makeJob();
        $task = $this->makeTask($job, estimatedHours: 10);
        $entry = $this->approvedEntry($job, $task, hours: 6);

        $this->actingAs($this->manager)
            ->post("/time-tracking/entries/{$entry->id}/reopen", ['reason' => 'Wrong task charged.'])
            ->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertSame(TimeEntry::STATUS_LOCKED, $entry->status);

        $correction = TimeEntry::where('corrects_id', $entry->id)->sole();
        $this->assertSame(TimeEntry::STATUS_DRAFT, $correction->status);
        $this->assertSame((float) $entry->hours, (float) $correction->hours);

        // The locked original no longer counts — only its correction would,
        // once approved.
        $this->assertSame('0.00', $task->fresh()->actual_hours);

        // An employee cannot reopen their own approved entry.
        $this->actingAs($this->employee)
            ->post("/time-tracking/entries/{$entry->id}/reopen", ['reason' => 'x'])
            ->assertForbidden();
    }

    public function test_an_already_locked_entry_cannot_be_reopened_again(): void
    {
        $job = $this->makeJob();
        $entry = $this->approvedEntry($job, hours: 4);

        $this->actingAs($this->manager)->post("/time-tracking/entries/{$entry->id}/reopen", ['reason' => 'First correction.']);
        $entry->refresh();

        $this->actingAs($this->manager)
            ->post("/time-tracking/entries/{$entry->id}/reopen", ['reason' => 'Second attempt.'])
            ->assertForbidden();
    }

    private function submittedEntry(Job $job): TimeEntry
    {
        $this->actingAs($this->employee)->post('/time-tracking/entries', [
            'job_id' => $job->id,
            'date' => '2026-08-10',
            'hours' => 5,
        ]);
        $entry = TimeEntry::sole();
        $this->actingAs($this->employee)->post("/time-tracking/entries/{$entry->id}/submit");

        return $entry->refresh();
    }

    private function approvedEntry(Job $job, ?JobTask $task = null, float $hours = 5): TimeEntry
    {
        $this->actingAs($this->employee)->post('/time-tracking/entries', [
            'job_id' => $job->id,
            'job_task_id' => $task?->id,
            'date' => '2026-08-10',
            'hours' => $hours,
        ]);
        $entry = TimeEntry::sole();
        $this->actingAs($this->employee)->post("/time-tracking/entries/{$entry->id}/submit");
        $this->actingAs($this->manager)->post("/time-tracking/entries/{$entry->id}/approve");

        return $entry->refresh();
    }

    private function makeJob(array $attributes = []): Job
    {
        return Job::create([
            // `TimeEntryPolicy::approve/reject/reopen` all require the acting
            // manager to own the job an entry is on — unset, as this fixture
            // left it, the manager can never actually approve anything here,
            // which is what made every post-approval assertion below fail
            // silently (an unauthorized approve() 403s, and 403 has no
            // session "errors" key, so `assertSessionHasNoErrors()` still
            // passes even though nothing happened).
            'user_id' => $this->manager->id,
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside Office Renovation',
            'client' => 'Riverside Properties LLC',
            'status' => 'in-progress',
            ...$attributes,
        ]);
    }

    private function makeTask(Job $job, float $estimatedHours = 8): JobTask
    {
        $schedule = JobSchedule::create([
            'job_id' => $job->id,
            'working_days' => [1, 2, 3, 4, 5],
        ]);

        return JobTask::create([
            'job_schedule_id' => $schedule->id,
            'job_id' => $job->id,
            'title' => 'Site survey',
            'estimated_hours' => $estimatedHours,
        ]);
    }
}
