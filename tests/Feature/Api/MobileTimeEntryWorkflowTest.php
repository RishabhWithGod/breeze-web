<?php

namespace Tests\Feature\Api;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mobile's edit/delete/approve/reject/reopen workflow for a time entry —
 * full parity with web's `TimeEntryController` (same `TimeEntryPolicy`,
 * same `TimeEntryWriteService`), plus the richer `index`/`show` payloads
 * (filters, `can` abilities, activity log, job/task detail).
 */
class MobileTimeEntryWorkflowTest extends TestCase
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

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function makeEntry(Job $job, User $user, array $attributes = []): TimeEntry
    {
        return TimeEntry::create([
            'job_id' => $job->id,
            'user_id' => $user->id,
            'date' => now()->toDateString(),
            'hours' => 3,
            'status' => TimeEntry::STATUS_DRAFT,
            'source' => TimeEntry::SOURCE_MANUAL,
            ...$attributes,
        ]);
    }

    // --- Update ---------------------------------------------------------

    /** Role-based staffing — the simpler of `ElectricianJobAccess`'s two paths, no schedule/task needed. */
    private function staffJob(Job $job, User $user): void
    {
        $job->assignments()->create([
            'role' => 'electrician',
            'name' => $user->name,
            'user_id' => $user->id,
            'assigned_at' => now(),
        ]);
    }

    public function test_the_owner_can_edit_their_own_draft_entry(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->staffJob($job, $user);
        $entry = $this->makeEntry($job, $user);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->putJson("/api/v1/time-entries/{$entry->id}", [
                'job_id' => $job->id,
                'date' => now()->toDateString(),
                'hours' => 5,
                'description' => 'Updated description',
            ])
            ->assertOk();

        $this->assertSame('Updated description', $entry->fresh()->description);
        $this->assertEqualsWithDelta(5.0, (float) $entry->fresh()->hours, 0.001);
    }

    public function test_an_approved_entry_cannot_be_edited(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $entry = $this->makeEntry($job, $user, ['status' => TimeEntry::STATUS_APPROVED]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->putJson("/api/v1/time-entries/{$entry->id}", [
                'job_id' => $job->id, 'date' => now()->toDateString(), 'hours' => 5,
            ])
            ->assertStatus(403);
    }

    public function test_a_user_cannot_edit_someone_elses_entry(): void
    {
        $owner = User::factory()->create(['role' => 'Electrician']);
        $intruder = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $entry = $this->makeEntry($job, $owner);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($intruder))
            ->putJson("/api/v1/time-entries/{$entry->id}", [
                'job_id' => $job->id, 'date' => now()->toDateString(), 'hours' => 5,
            ])
            ->assertStatus(403);
    }

    // --- Delete -----------------------------------------------------------

    public function test_the_owner_can_delete_their_own_draft_entry(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $entry = $this->makeEntry($job, $user);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->deleteJson("/api/v1/time-entries/{$entry->id}")
            ->assertOk();

        $this->assertSoftDeleted('time_entries', ['id' => $entry->id]);
    }

    public function test_an_approved_entry_cannot_be_deleted(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $entry = $this->makeEntry($job, $user, ['status' => TimeEntry::STATUS_APPROVED]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->deleteJson("/api/v1/time-entries/{$entry->id}")
            ->assertStatus(403);
    }

    // --- Approve / Reject / Reopen -----------------------------------------

    public function test_a_manager_can_approve_a_submitted_entry_on_their_own_job(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $electrician = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob(['user_id' => $manager->id]);
        $entry = $this->makeEntry($job, $electrician, ['status' => TimeEntry::STATUS_SUBMITTED]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->postJson("/api/v1/time-entries/{$entry->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $fresh = $entry->fresh();
        $this->assertSame(TimeEntry::STATUS_APPROVED, $fresh->status);
        $this->assertSame($manager->id, $fresh->approved_by);
    }

    public function test_an_electrician_cannot_approve_an_entry(): void
    {
        $electrician = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $entry = $this->makeEntry($job, $electrician, ['status' => TimeEntry::STATUS_SUBMITTED]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($electrician))
            ->postJson("/api/v1/time-entries/{$entry->id}/approve")
            ->assertStatus(403);
    }

    public function test_a_manager_cannot_approve_an_entry_on_a_job_they_do_not_own(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $otherManager = User::factory()->create(['role' => 'Project Manager']);
        $electrician = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob(['user_id' => $otherManager->id]);
        $entry = $this->makeEntry($job, $electrician, ['status' => TimeEntry::STATUS_SUBMITTED]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->postJson("/api/v1/time-entries/{$entry->id}/approve")
            ->assertStatus(403);
    }

    public function test_a_manager_can_reject_a_submitted_entry_with_a_reason(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $electrician = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob(['user_id' => $manager->id]);
        $entry = $this->makeEntry($job, $electrician, ['status' => TimeEntry::STATUS_SUBMITTED]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->postJson("/api/v1/time-entries/{$entry->id}/reject", ['reason' => 'Hours look off, please recheck.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame('Hours look off, please recheck.', $entry->fresh()->rejection_reason);
    }

    public function test_rejecting_without_a_reason_is_rejected(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $electrician = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob(['user_id' => $manager->id]);
        $entry = $this->makeEntry($job, $electrician, ['status' => TimeEntry::STATUS_SUBMITTED]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->postJson("/api/v1/time-entries/{$entry->id}/reject")
            ->assertStatus(422);
    }

    public function test_a_manager_can_reopen_an_approved_entry_creating_a_correction(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $electrician = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob(['user_id' => $manager->id]);
        $entry = $this->makeEntry($job, $electrician, ['status' => TimeEntry::STATUS_APPROVED, 'hours' => 6]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->postJson("/api/v1/time-entries/{$entry->id}/reopen", ['reason' => 'Wrong job selected.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        $this->assertSame(TimeEntry::STATUS_LOCKED, $entry->fresh()->status);
        $correctionId = $response->json('data.id');
        $this->assertNotSame($entry->id, $correctionId);
        $this->assertDatabaseHas('time_entries', ['id' => $correctionId, 'corrects_id' => $entry->id]);
    }

    // --- Index filters + abilities -------------------------------------------

    public function test_index_filters_by_status(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->makeEntry($job, $user, ['status' => TimeEntry::STATUS_DRAFT]);
        $this->makeEntry($job, $user, ['status' => TimeEntry::STATUS_SUBMITTED]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/time-entries?status=submitted')
            ->assertOk();

        $statuses = array_column($response->json('data.entries'), 'status');
        $this->assertSame(['submitted'], $statuses);
    }

    public function test_index_sends_can_abilities_for_ui_gating(): void
    {
        $electrician = User::factory()->create(['role' => 'Electrician']);
        $manager = User::factory()->create(['role' => 'Project Manager']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($electrician))
            ->getJson('/api/v1/time-entries')
            ->assertOk()
            ->assertJsonPath('data.can.approve', false)
            ->assertJsonPath('data.can.viewCrew', false);

        auth()->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->getJson('/api/v1/time-entries')
            ->assertOk()
            ->assertJsonPath('data.can.approve', true)
            ->assertJsonPath('data.can.viewCrew', true)
            ->assertJsonPath('data.can.viewReports', true);
    }

    public function test_a_manager_with_view_crew_sees_entries_beyond_their_own(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $electrician = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->makeEntry($job, $electrician);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->getJson('/api/v1/time-entries')
            ->assertOk();

        $this->assertCount(1, $response->json('data.entries'));
    }

    // --- Show full detail payload -------------------------------------------

    public function test_show_returns_the_full_detail_payload(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $entry = $this->makeEntry($job, $user, ['description' => 'Panel install']);
        $entry->recordInitialStatus();
        $entry->recordActivity('created', 'Time entry logged.');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/time-entries/{$entry->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'entry', 'activities', 'employee', 'job', 'corrects', 'corrections',
                    'jobTimeSummary', 'relatedEntries',
                    'can' => ['update', 'delete', 'submit', 'approve', 'reject', 'reopen', 'viewJobCosts'],
                ],
            ])
            ->assertJsonPath('data.job.name', 'Riverside Office Renovation')
            ->assertJsonPath('data.can.update', true)
            ->assertJsonPath('data.can.approve', false);

        $this->assertGreaterThanOrEqual(1, count($response->json('data.activities')));
    }

    /**
     * Regression: `days()`/`day()` already show a Foreman any crew member's
     * entries under `viewCrew` with no job-ownership check, so `show()`
     * enforcing job ownership on top of that made opening an entry from a
     * list the same foreman could already see 403.
     */
    public function test_a_foreman_can_view_a_crew_members_entry_on_a_job_they_do_not_own(): void
    {
        $foreman = User::factory()->create(['role' => 'Foreman']);
        $electrician = User::factory()->create(['role' => 'Electrician']);
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $job = $this->makeJob(['user_id' => $owner->id]);
        $entry = $this->makeEntry($job, $electrician);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foreman))
            ->getJson("/api/v1/time-entries/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('data.entry.id', $entry->id);
    }
}
