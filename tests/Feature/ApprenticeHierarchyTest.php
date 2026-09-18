<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobApprenticeAssignment;
use App\Models\JobSchedule;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Foreman → Journeyman → Apprentice assignment hierarchy, end to end:
 * a foreman puts an apprentice under a journeyman already staffed on a job,
 * and from that point on the apprentice sees exactly that job — basic info
 * and check-in/out only, nothing a journeyman or foreman can do.
 */
class ApprenticeHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private User $planner;

    private Team $north;

    private Foreman $journeyman;

    private Foreman $apprentice;

    private Job $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planner = User::factory()->create(['role' => 'Project Manager']);
        $this->north = Team::create(['name' => 'North Crew']);

        $this->journeyman = Foreman::create([
            'name' => 'Priya Raman', 'initials' => 'PR', 'team_id' => $this->north->id, 'role' => 'journeyman',
        ]);
        $this->apprentice = Foreman::create([
            'name' => 'Robin Ashby', 'initials' => 'RA', 'team_id' => $this->north->id, 'role' => 'apprentice',
        ]);

        $this->job = Job::create([
            'user_id' => $this->planner->id,
            'team_id' => $this->north->id,
            'name' => 'Riser rewire',
            'client' => 'Harborview',
            'status' => 'scheduled',
            'start_date' => now()->toDateString(),
        ]);

        $schedule = JobSchedule::create(['job_id' => $this->job->id, 'working_days' => [1, 2, 3, 4, 5]]);
        $schedule->tasks()->create([
            'job_id' => $this->job->id,
            'job_schedule_id' => $schedule->id,
            'title' => 'Rough-in',
            'position' => 0,
            'status' => 'pending',
            'foreman_id' => $this->journeyman->id,
        ]);
    }

    private function makeMobileAccount(Foreman $member, string $webRoleLabel): User
    {
        $user = User::factory()->create([
            'name' => $member->name,
            'role' => $webRoleLabel,
            'registration_source' => User::SOURCE_MOBILE,
            'status' => User::STATUS_ACTIVE,
        ]);
        $member->user_id = $user->id;
        $member->save();

        return $user;
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /* ------------------------------------------- mobile: a foreman assigns */

    public function test_a_foremans_job_detail_offers_only_staffed_journeymen_and_their_teams_apprentices(): void
    {
        $foreman = Foreman::create(['name' => 'Dana', 'initials' => 'DW', 'team_id' => $this->north->id, 'role' => 'foreman']);
        $foremanUser = $this->makeMobileAccount($foreman, 'Foreman');
        $this->job->tasks()->first()->update(['supervisor_id' => $foreman->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanUser))
            ->getJson("/api/v1/jobs/{$this->job->id}")
            ->assertOk();

        $response->assertJsonPath('data.canAssignApprentice', true);
        $response->assertJsonCount(1, 'data.assignableJourneymen');
        $response->assertJsonPath('data.assignableJourneymen.0.id', $this->journeyman->id);
        $response->assertJsonCount(1, "data.apprenticesByJourneyman.{$this->journeyman->id}");
    }

    public function test_a_foreman_assigns_an_apprentice_under_a_staffed_journeyman(): void
    {
        $foreman = Foreman::create(['name' => 'Dana', 'initials' => 'DW', 'team_id' => $this->north->id, 'role' => 'foreman']);
        $foremanUser = $this->makeMobileAccount($foreman, 'Foreman');
        $this->job->tasks()->first()->update(['supervisor_id' => $foreman->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanUser))
            ->postJson("/api/v1/jobs/{$this->job->id}/apprentices", [
                'journeyman_id' => $this->journeyman->id,
                'apprentice_id' => $this->apprentice->id,
            ])
            ->assertCreated();

        $assignment = JobApprenticeAssignment::sole();
        $this->assertSame($this->job->id, $assignment->job_id);
        $this->assertSame($this->journeyman->id, $assignment->journeyman_id);
        $this->assertSame($this->apprentice->id, $assignment->apprentice_id);
    }

    public function test_a_journeyman_not_staffed_on_the_job_is_refused(): void
    {
        $foreman = Foreman::create(['name' => 'Dana', 'initials' => 'DW', 'team_id' => $this->north->id, 'role' => 'foreman']);
        $foremanUser = $this->makeMobileAccount($foreman, 'Foreman');
        $this->job->tasks()->first()->update(['supervisor_id' => $foreman->id]);

        $stranger = Foreman::create(['name' => 'Sam', 'initials' => 'SA', 'team_id' => $this->north->id, 'role' => 'journeyman']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanUser))
            ->postJson("/api/v1/jobs/{$this->job->id}/apprentices", [
                'journeyman_id' => $stranger->id,
                'apprentice_id' => $this->apprentice->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('journeyman_id');

        $this->assertSame(0, JobApprenticeAssignment::count());
    }

    public function test_an_apprentice_off_the_journeymans_team_is_refused(): void
    {
        $foreman = Foreman::create(['name' => 'Dana', 'initials' => 'DW', 'team_id' => $this->north->id, 'role' => 'foreman']);
        $foremanUser = $this->makeMobileAccount($foreman, 'Foreman');
        $this->job->tasks()->first()->update(['supervisor_id' => $foreman->id]);

        $south = Team::create(['name' => 'South Crew']);
        $offTeam = Foreman::create(['name' => 'Sam', 'initials' => 'SA', 'team_id' => $south->id, 'role' => 'apprentice']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanUser))
            ->postJson("/api/v1/jobs/{$this->job->id}/apprentices", [
                'journeyman_id' => $this->journeyman->id,
                'apprentice_id' => $offTeam->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('apprentice_id');

        $this->assertSame(0, JobApprenticeAssignment::count());
    }

    public function test_a_journeyman_cannot_assign_an_apprentice(): void
    {
        $journeymanUser = $this->makeMobileAccount($this->journeyman, 'Journeyman');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($journeymanUser))
            ->postJson("/api/v1/jobs/{$this->job->id}/apprentices", [
                'journeyman_id' => $this->journeyman->id,
                'apprentice_id' => $this->apprentice->id,
            ])
            ->assertStatus(403);

        $this->assertSame(0, JobApprenticeAssignment::count());
    }

    /** The web app never exposed the assign action to begin with — the route simply doesn't exist there. */
    public function test_the_web_app_has_no_assign_route(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('jobs.apprentices.store'));
    }

    public function test_a_manager_sees_the_assignment_on_the_web_job_page_but_no_assign_action(): void
    {
        JobApprenticeAssignment::create([
            'job_id' => $this->job->id,
            'journeyman_id' => $this->journeyman->id,
            'apprentice_id' => $this->apprentice->id,
        ]);

        $this->actingAs($this->planner)
            ->get(route('jobs.show', $this->job))
            ->assertInertia(fn (Assert $page) => $page
                ->has('apprenticeAssignments', 1)
                ->where('apprenticeAssignments.0.apprenticeName', 'Robin Ashby')
                ->where('apprenticeAssignments.0.journeymanName', 'Priya Raman')
                ->where('canManageApprentices', true)
                ->missing('assignableJourneymen')
                ->missing('apprenticesByJourneyman'));
    }

    public function test_a_manager_removes_an_assignment_from_the_web(): void
    {
        $assignment = JobApprenticeAssignment::create([
            'job_id' => $this->job->id,
            'journeyman_id' => $this->journeyman->id,
            'apprentice_id' => $this->apprentice->id,
        ]);

        $this->actingAs($this->planner)
            ->delete(route('jobs.apprentices.destroy', [$this->job, $assignment]))
            ->assertRedirect();

        $this->assertSame(0, JobApprenticeAssignment::count());
    }

    /* --------------------------------------------- mobile: what an apprentice sees */

    public function test_an_apprentice_sees_only_their_assigned_job(): void
    {
        $apprenticeUser = $this->makeMobileAccount($this->apprentice, 'Apprentice');
        JobApprenticeAssignment::create([
            'job_id' => $this->job->id,
            'journeyman_id' => $this->journeyman->id,
            'apprentice_id' => $this->apprentice->id,
        ]);

        $otherJob = Job::create([
            'user_id' => $this->planner->id,
            'name' => 'Unrelated job',
            'client' => 'Someone else',
            'status' => 'scheduled',
        ]);

        $index = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($apprenticeUser))
            ->getJson('/api/v1/jobs')
            ->assertOk();

        $ids = array_column($index->json('data.jobs'), 'id');
        $this->assertContains($this->job->id, $ids);
        $this->assertNotContains($otherJob->id, $ids);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($apprenticeUser))
            ->getJson("/api/v1/jobs/{$otherJob->id}")
            ->assertStatus(403);
    }

    public function test_an_apprentices_job_show_response_is_basic_info_only(): void
    {
        $apprenticeUser = $this->makeMobileAccount($this->apprentice, 'Apprentice');
        JobApprenticeAssignment::create([
            'job_id' => $this->job->id,
            'journeyman_id' => $this->journeyman->id,
            'apprentice_id' => $this->apprentice->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($apprenticeUser))
            ->getJson("/api/v1/jobs/{$this->job->id}")
            ->assertOk();

        $response->assertJsonPath('data.id', $this->job->id);
        $response->assertJsonMissingPath('data.assignments');
        $response->assertJsonMissingPath('data.crewTime');
        $response->assertJsonMissingPath('data.openTasksCount');
        $response->assertJsonMissingPath('data.estimatedHours');
        $response->assertJsonMissingPath('data.pendingForemen');
    }

    public function test_an_apprentice_cannot_see_tasks(): void
    {
        $apprenticeUser = $this->makeMobileAccount($this->apprentice, 'Apprentice');
        JobApprenticeAssignment::create([
            'job_id' => $this->job->id, 'journeyman_id' => $this->journeyman->id, 'apprentice_id' => $this->apprentice->id,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($apprenticeUser))
            ->getJson("/api/v1/jobs/{$this->job->id}/tasks")
            ->assertStatus(403);

        $task = $this->job->tasks()->first();
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($apprenticeUser))
            ->getJson("/api/v1/tasks/{$task->id}")
            ->assertStatus(403);
    }

    public function test_an_apprentice_cannot_see_materials(): void
    {
        $apprenticeUser = $this->makeMobileAccount($this->apprentice, 'Apprentice');
        JobApprenticeAssignment::create([
            'job_id' => $this->job->id, 'journeyman_id' => $this->journeyman->id, 'apprentice_id' => $this->apprentice->id,
        ]);

        $task = $this->job->tasks()->first();
        $item = $task->estimateItems()->create([
            'estimate_id' => \App\Models\Estimate::create([
                'number' => 'EST-1', 'client' => 'Harborview', 'project' => 'Riser', 'issued_on' => now()->toDateString(),
                'amount' => 100, 'status' => 'draft',
            ])->id,
            'category' => 'labor', 'description' => 'Conduit', 'unit' => 'ea', 'quantity' => 1, 'unit_cost' => 5,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($apprenticeUser))
            ->patchJson("/api/v1/estimate-items/{$item->id}/completion", ['completed' => true])
            ->assertStatus(403);
    }

    public function test_an_apprentice_cannot_change_job_status(): void
    {
        $apprenticeUser = $this->makeMobileAccount($this->apprentice, 'Apprentice');
        JobApprenticeAssignment::create([
            'job_id' => $this->job->id, 'journeyman_id' => $this->journeyman->id, 'apprentice_id' => $this->apprentice->id,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($apprenticeUser))
            ->postJson("/api/v1/jobs/{$this->job->id}/status", ['status' => 'in-progress'])
            ->assertStatus(403);

        $this->assertSame('scheduled', $this->job->fresh()->status);
    }

    public function test_an_apprentice_cannot_assign_anyone(): void
    {
        $apprenticeUser = $this->makeMobileAccount($this->apprentice, 'Apprentice');

        // No web session for a mobile-onboarded apprentice, but the same
        // authorization gate must hold if the endpoint is ever reached —
        // policy-level, not just "no button on screen".
        $this->assertFalse(
            app(\App\Policies\JobSchedulePolicy::class)->assignApprentice($apprenticeUser, $this->job),
        );
    }

    public function test_an_apprentice_can_check_in_and_out(): void
    {
        $apprenticeUser = $this->makeMobileAccount($this->apprentice, 'Apprentice');
        JobApprenticeAssignment::create([
            'job_id' => $this->job->id, 'journeyman_id' => $this->journeyman->id, 'apprentice_id' => $this->apprentice->id,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($apprenticeUser))
            ->getJson("/api/v1/jobs/{$this->job->id}/attendance")
            ->assertOk();
    }

    /* ------------------------------------------- journeyman / foreman retain access */

    public function test_a_journeyman_can_start_the_job(): void
    {
        $journeymanUser = $this->makeMobileAccount($this->journeyman, 'Journeyman');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($journeymanUser))
            ->postJson("/api/v1/jobs/{$this->job->id}/status", ['status' => 'in-progress'])
            ->assertOk();

        $this->assertSame('in-progress', $this->job->fresh()->status);
    }

    public function test_a_foreman_can_still_see_full_job_detail(): void
    {
        $foreman = Foreman::create(['name' => 'Dana', 'initials' => 'DW', 'team_id' => $this->north->id, 'role' => 'foreman']);
        $foremanUser = $this->makeMobileAccount($foreman, 'Foreman');
        // Staffed as the task's overseer — a mobile account stays scoped to
        // its own staffing regardless of role, same as before this feature.
        $this->job->tasks()->first()->update(['supervisor_id' => $foreman->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanUser))
            ->getJson("/api/v1/jobs/{$this->job->id}")
            ->assertOk();

        $response->assertJsonStructure(['data' => ['assignments', 'crewTime', 'openTasksCount']]);
    }

    /** A journeyman sees who is assigned under them, but not the assign picker itself — that's foreman-only. */
    public function test_a_journeyman_sees_the_assigned_apprentice_but_not_the_assign_picker(): void
    {
        $journeymanUser = $this->makeMobileAccount($this->journeyman, 'Journeyman');
        JobApprenticeAssignment::create([
            'job_id' => $this->job->id, 'journeyman_id' => $this->journeyman->id, 'apprentice_id' => $this->apprentice->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($journeymanUser))
            ->getJson("/api/v1/jobs/{$this->job->id}")
            ->assertOk();

        $response->assertJsonCount(1, 'data.apprenticeAssignments');
        $response->assertJsonPath('data.apprenticeAssignments.0.apprenticeName', 'Robin Ashby');
        $response->assertJsonPath('data.canAssignApprentice', false);
        $response->assertJsonMissingPath('data.assignableJourneymen');
    }
}
