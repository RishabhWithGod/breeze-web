<?php

namespace Tests\Feature\Api;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\TeamMember;
use App\Models\TimeEntry;
use App\Models\TimerSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Completing a job from mobile now finalizes its worked-time totals — the
 * foundation for a future web-side "why did this run over" report. Nothing
 * here duplicates `MobileJobsAndTasksTest`'s own job/task access coverage;
 * this is only the completion-time hours/delay bookkeeping added alongside
 * the job-start-date gate.
 */
class MobileJobCompletionDelayTest extends TestCase
{
    use RefreshDatabase;

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

    private function makeElectrician(string $name = 'Priya Raman'): array
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'Electrician']);
        $member = TeamMember::create(['name' => $name, 'initials' => 'PR', 'role' => 'Electrician', 'user_id' => $user->id]);

        return [$user, $member];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function staffOnJob(Job $job, User $user, TeamMember $member): void
    {
        $job->assignments()->create([
            'user_id' => $user->id,
            'team_member_id' => $member->id,
            'name' => $member->name,
            'role' => 'electrician',
        ]);
    }

    private function makeTimeEntry(Job $job, User $user, TeamMember $member, float $hours): TimeEntry
    {
        return TimeEntry::create([
            'job_id' => $job->id,
            'user_id' => $user->id,
            'team_member_id' => $member->id,
            'date' => now()->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'break_minutes' => 0,
            'hours' => $hours,
            'source' => TimeEntry::SOURCE_MANUAL,
            'status' => TimeEntry::STATUS_DRAFT,
        ]);
    }

    public function test_completing_a_job_with_no_time_logged_and_no_estimate_needs_no_reason(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob(['estimated_hours' => null]);
        $this->staffOnJob($job, $user, $member);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'completed'])
            ->assertOk();

        // An electrician's own "complete" only ever gets a job as far as
        // ready-for-review now — see `MobileJobCompletionWorkflowTest` for
        // the two-step sign-off itself. The hours/delay bookkeeping this
        // file covers already happens at this interim step.
        $job->refresh();
        $this->assertSame('in-progress', $job->status);
        $this->assertNotNull($job->ready_for_review_at);
        $this->assertSame('0.00', $job->actual_hours);
        $this->assertSame('0.00', $job->delay_hours);
        $this->assertNull($job->delay_reason);
    }

    public function test_completing_a_job_within_its_estimate_needs_no_reason(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob(['estimated_hours' => 10]);
        $this->staffOnJob($job, $user, $member);
        $this->makeTimeEntry($job, $user, $member, 6);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'completed'])
            ->assertOk();

        $job->refresh();
        $this->assertSame('in-progress', $job->status);
        $this->assertNotNull($job->ready_for_review_at);
        $this->assertSame('6.00', $job->actual_hours);
        $this->assertSame('0.00', $job->delay_hours);
        $this->assertNull($job->delay_reason);
    }

    public function test_completing_a_job_that_ran_over_its_estimate_requires_a_reason(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob(['estimated_hours' => 4]);
        $this->staffOnJob($job, $user, $member);
        $this->makeTimeEntry($job, $user, $member, 7);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'completed'])
            ->assertStatus(422);

        $response->assertJsonPath('errors.code', 'delay_reason_required');
        $response->assertJsonPath('errors.delayHours', 3);

        $job->refresh();
        $this->assertSame('in-progress', $job->status);
        $this->assertNull($job->actual_hours);
        $this->assertNull($job->delay_hours);
    }

    public function test_completing_an_overrun_job_with_a_reason_saves_the_delay_and_completes_it(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob(['estimated_hours' => 4]);
        $this->staffOnJob($job, $user, $member);
        $this->makeTimeEntry($job, $user, $member, 7);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/status", [
                'status' => 'completed',
                'reason' => 'Extra conduit run the estimate did not account for.',
            ])
            ->assertOk();

        $job->refresh();
        $this->assertSame('in-progress', $job->status);
        $this->assertNotNull($job->ready_for_review_at);
        $this->assertSame('7.00', $job->actual_hours);
        $this->assertSame('3.00', $job->delay_hours);
        $this->assertSame('Extra conduit run the estimate did not account for.', $job->delay_reason);
    }

    public function test_completing_a_job_stops_the_technicians_own_running_timer_on_it_first(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob(['estimated_hours' => 10]);
        $this->staffOnJob($job, $user, $member);

        $session = TimerSession::create([
            'user_id' => $user->id,
            'job_id' => $job->id,
            'team_member_id' => $member->id,
            'started_at' => now()->subHours(2),
            'accumulated_seconds' => 0,
            'status' => TimerSession::STATUS_RUNNING,
            'billable' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'completed'])
            ->assertOk();

        $this->assertModelMissing($session);
        $job->refresh();
        // The running clock's ~2 hours is folded into the finalized total —
        // completing a job must not lose whatever was still on the clock.
        $this->assertEqualsWithDelta(2.0, (float) $job->actual_hours, 0.05);

        $this->assertDatabaseHas('time_entries', [
            'job_id' => $job->id,
            'user_id' => $user->id,
            'source' => TimeEntry::SOURCE_TIMER,
        ]);
    }

    public function test_completing_a_job_does_not_touch_another_users_running_timer(): void
    {
        [$user, $member] = $this->makeElectrician();
        [$otherUser, $otherMember] = $this->makeElectrician('Other Tech');
        $job = $this->makeJob(['estimated_hours' => 10]);
        $this->staffOnJob($job, $user, $member);
        $this->staffOnJob($job, $otherUser, $otherMember);

        $session = TimerSession::create([
            'user_id' => $otherUser->id,
            'job_id' => $job->id,
            'team_member_id' => $otherMember->id,
            'started_at' => now()->subHour(),
            'accumulated_seconds' => 0,
            'status' => TimerSession::STATUS_RUNNING,
            'billable' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'completed'])
            ->assertOk();

        $this->assertModelExists($session);
    }

    public function test_a_jobs_show_response_carries_hours_and_delay_fields(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob(['estimated_hours' => 5]);
        $this->staffOnJob($job, $user, $member);
        $this->makeTimeEntry($job, $user, $member, 2);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.estimatedHours', 5)
            ->assertJsonPath('data.workedHoursSoFar', 2)
            ->assertJsonPath('data.actualHours', null)
            ->assertJsonPath('data.delayHours', null)
            ->assertJsonPath('data.delayReason', null);
    }
}
