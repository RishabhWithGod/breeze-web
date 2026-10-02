<?php

namespace Tests\Feature\Api;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobTask;
use App\Models\SyncConflict;
use App\Models\User;
use App\Services\Scheduling\ScheduleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A change made in the field with no signal can clash with what the office did to the same task
 * meanwhile. Different things never clash; the same thing moved to two values does, and the
 * technician decides — or sends it up to a manager.
 */
class MobileSyncConflictTest extends TestCase
{
    use RefreshDatabase;

    private User $foremanUser;

    private JobTask $task;

    private Job $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->foremanUser = User::factory()->create(['name' => 'Sam', 'role' => 'Foreman', 'registration_source' => User::SOURCE_MOBILE, 'status' => User::STATUS_ACTIVE]);
        $foreman = new Foreman(['name' => 'Sam', 'initials' => 'SA', 'role' => 'foreman']);
        $foreman->user_id = $this->foremanUser->id;
        $foreman->save();

        $this->job = Job::create(['foreman_id' => $foreman->id, 'name' => 'Riverside', 'client' => 'Riverside LLC', 'status' => 'in-progress']);
        $schedule = app(ScheduleBuilder::class)->build($this->job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $this->task = $schedule->tasks()->first();
        $this->task->forceFill(['status' => 'in-progress', 'notes' => 'Original note'])->save();
    }

    private function patchStatus(array $body)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$this->foremanUser->createToken('t')->plainTextToken)
            ->patchJson("/api/v1/tasks/{$this->task->id}/status", $body);
    }

    public function test_a_status_change_after_the_office_moved_the_task_elsewhere_is_a_conflict(): void
    {
        // The office completes it while the field, offline, wants it blocked.
        $this->task->forceFill(['status' => 'completed'])->save();

        $this->patchStatus(['status' => 'blocked', 'base_status' => 'in-progress'])
            ->assertStatus(409)
            ->assertJsonPath('errors.code', 'conflict')
            ->assertJsonPath('errors.conflict.fields.0.key', 'status')
            ->assertJsonPath('errors.conflict.fields.0.field.value', 'blocked')
            ->assertJsonPath('errors.conflict.fields.0.office.value', 'completed')
            ->assertJsonPath('errors.conflict.canOverride', true);

        $this->assertSame('completed', $this->task->fresh()->status, 'nothing was applied');
    }

    public function test_changes_that_do_not_clash_just_apply(): void
    {
        // Nobody else touched it.
        $this->patchStatus(['status' => 'blocked', 'base_status' => 'in-progress'])->assertOk();
        $this->assertSame('blocked', $this->task->fresh()->status);

        // The office already did the very same thing: also fine.
        $this->task->forceFill(['status' => 'completed'])->save();
        $this->patchStatus(['status' => 'completed', 'base_status' => 'in-progress'])->assertOk();

        // No base sent (an online change): never compared.
        $this->patchStatus(['status' => 'in-progress'])->assertOk();
    }

    public function test_the_foreman_can_keep_the_field_version_over_the_office(): void
    {
        $this->task->forceFill(['status' => 'completed'])->save();

        $this->patchStatus(['status' => 'blocked', 'base_status' => 'in-progress', 'resolution' => 'field'])->assertOk();
        $this->assertSame('blocked', $this->task->fresh()->status);
    }

    public function test_completion_notes_clash_only_when_both_sides_changed_them(): void
    {
        $this->task->forceFill(['notes' => 'Customer requested follow-up next week.'])->save();
        $this->app['auth']->forgetGuards();
        $token = $this->foremanUser->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/tasks/{$this->task->id}/complete", ['notes' => 'Replaced filter. System running normally.', 'base_status' => 'in-progress', 'base_notes' => 'Original note'])
            ->assertStatus(409)
            ->assertJsonPath('errors.conflict.fields.0.key', 'notes')
            ->assertJsonPath('errors.conflict.fields.0.office.value', 'Customer requested follow-up next week.');
    }

    public function test_a_crew_member_cannot_force_their_version_over_the_office(): void
    {
        $journeyman = User::factory()->create(['role' => 'Journeyman', 'registration_source' => User::SOURCE_MOBILE, 'status' => User::STATUS_ACTIVE]);
        $member = \App\Models\TeamMember::create(['name' => $journeyman->name, 'initials' => 'J', 'role' => 'Journeyman', 'user_id' => $journeyman->id]);
        $this->task->assignments()->create(['team_member_id' => $member->id, 'role' => 'electrician']);
        $this->task->forceFill(['status' => 'blocked'])->save();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$journeyman->createToken('t')->plainTextToken)
            ->postJson("/api/v1/tasks/{$this->task->id}/complete", ['base_status' => 'in-progress'])
            ->assertStatus(409)
            ->assertJsonPath('errors.conflict.canOverride', false);
    }

    public function test_an_escalated_conflict_reaches_the_office_who_settle_it(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $this->job->forceFill(['user_id' => $manager->id])->save();
        $this->task->forceFill(['status' => 'completed'])->save();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$this->foremanUser->createToken('t')->plainTextToken)
            ->postJson('/api/v1/sync-conflicts', [
                'entity' => 'task', 'entity_id' => $this->task->id,
                'fields' => [['key' => 'status', 'label' => 'Task Status', 'kind' => 'choice',
                    'field' => ['value' => 'blocked', 'at' => now()->toISOString()],
                    'office' => ['value' => 'completed', 'at' => now()->toISOString()]]],
            ])->assertCreated();

        $conflict = SyncConflict::sole();
        $this->assertTrue($conflict->isOpen());
        $this->assertTrue(\App\Models\AppNotification::where('user_id', $manager->id)->where('type', 'sync-conflict')->exists());

        $this->actingAs($manager)
            ->post("/sync-conflicts/{$conflict->id}/resolve", ['resolution' => 'apply_field'])
            ->assertRedirect();

        $this->assertSame('blocked', $this->task->fresh()->status);
        $this->assertSame('resolved', $conflict->fresh()->status);
        $this->assertSame($manager->id, $conflict->fresh()->resolved_by);
    }
}
