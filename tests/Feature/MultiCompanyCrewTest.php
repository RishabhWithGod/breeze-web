<?php

namespace Tests\Feature;

use App\Models\CompanyProfile;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Scheduling\ScheduleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Teams, foremen, journeymen and apprentices belong to a company; one company
 * never sees another's.
 */
class MultiCompanyCrewTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: CompanyProfile, 1: User} */
    private function company(string $name): array
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $company = CompanyProfile::create([
            'user_id' => $manager->id, 'name' => $name, 'business_address' => '1 Main St',
            'primary_contact' => 'Alex', 'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        $manager->forceFill(['company_id' => $company->id])->save();

        return [$company, $manager->fresh()];
    }

    private function applicant(CompanyProfile $company, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'Journeyman']);
        $user->forceFill([
            'status' => User::STATUS_PENDING_APPROVAL,
            'registration_source' => User::SOURCE_MOBILE,
            'company_id' => $company->id,
        ])->save();

        return $user;
    }

    public function test_what_a_manager_adds_belongs_to_their_company(): void
    {
        [$volt, $voltManager] = $this->company('Volt & Co');

        $this->actingAs($voltManager)->post(route('teams.store'), ['name' => 'Crew A'])->assertRedirect();
        $team = Team::withoutGlobalScopes()->where('name', 'Crew A')->sole();
        $this->assertSame($volt->id, $team->company_id);

        $this->actingAs($voltManager)->post(route('foremen.store'), [
            'name' => 'Dana Wu', 'role' => 'foreman', 'team_id' => $team->id,
            'email' => 'dana@example.com', 'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x',
        ])->assertSessionHasNoErrors();
        $dana = Foreman::withoutGlobalScopes()->where('name', 'Dana Wu')->sole();
        $this->assertSame($volt->id, $dana->company_id);
        // Their mobile login belongs to the company too, as does their time-tracking record.
        $this->assertSame($volt->id, User::where('email', 'dana@example.com')->sole()->company_id);
        $this->assertSame($volt->id, TeamMember::withoutGlobalScopes()->where('user_id', $dana->user_id)->sole()->company_id);
    }

    public function test_one_company_never_sees_anothers_crews(): void
    {
        [$volt, $voltManager] = $this->company('Volt & Co');
        [$rival, $rivalManager] = $this->company('Rival Co');

        $this->actingAs($voltManager)->post(route('teams.store'), ['name' => 'Crew A']);
        $voltTeam = Team::withoutGlobalScopes()->where('company_id', $volt->id)->sole();
        (new Foreman(['name' => 'Dana Wu', 'initials' => 'DW', 'team_id' => $voltTeam->id]))->forceFill(['company_id' => $volt->id])->save();
        TeamMember::create(['name' => 'Sam', 'initials' => 'S', 'role' => 'Journeyman', 'company_id' => $volt->id]);

        // Rival sees none of it...
        $this->actingAs($rivalManager)->get(route('teams.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('teams.data', 0)
                ->has('unassigned', 0)
                ->has('teamOptions', 0));
        $this->assertSame(0, Team::count() + Foreman::count() + TeamMember::count());

        // ...and cannot open, edit or delete it by address either.
        $this->actingAs($rivalManager)->get(route('foremen.show', Foreman::withoutGlobalScopes()->firstOrFail()))->assertNotFound();
        $this->actingAs($rivalManager)->get(route('foremen.edit', Foreman::withoutGlobalScopes()->firstOrFail()))->assertNotFound();

        // Volt still has everything.
        $this->actingAs($voltManager)->get(route('teams.index'))
            ->assertInertia(fn (Assert $page) => $page->has('teams.data', 1));
    }

    public function test_two_companies_can_both_have_a_crew_with_the_same_name(): void
    {
        [, $voltManager] = $this->company('Volt & Co');
        [, $rivalManager] = $this->company('Rival Co');

        $this->actingAs($voltManager)->post(route('teams.store'), ['name' => 'Crew A'])->assertSessionHasNoErrors();
        $this->actingAs($rivalManager)->post(route('teams.store'), ['name' => 'Crew A'])->assertSessionHasNoErrors();
        // ...but not twice inside one company.
        $this->actingAs($voltManager)->post(route('teams.store'), ['name' => 'Crew A'])->assertSessionHasErrors('name');

        $this->assertSame(2, Team::withoutGlobalScopes()->where('name', 'Crew A')->count());
    }

    public function test_a_team_from_another_company_cannot_be_named_in_a_form(): void
    {
        [, $voltManager] = $this->company('Volt & Co');
        [$rival, $rivalManager] = $this->company('Rival Co');
        $this->actingAs($rivalManager)->post(route('teams.store'), ['name' => 'Rival Crew']);
        $rivalTeam = Team::withoutGlobalScopes()->where('company_id', $rival->id)->sole();

        $this->actingAs($voltManager)->post(route('foremen.store'), [
            'name' => 'Dana Wu', 'role' => 'foreman', 'team_id' => $rivalTeam->id,
            'email' => 'dana@example.com', 'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x',
        ])->assertSessionHasErrors('team_id');
    }

    public function test_an_application_goes_only_to_the_company_chosen(): void
    {
        [$volt, $voltManager] = $this->company('Volt & Co');
        [$rival, $rivalManager] = $this->company('Rival Co');
        $this->applicant($volt, 'Jamie');

        $this->actingAs($voltManager)->get(route('teams.index'))
            ->assertInertia(fn (Assert $page) => $page->has('pendingTechnicians', 1)->where('pendingTechnicians.0.name', 'Jamie'));
        $this->actingAs($rivalManager)->get(route('teams.index'))
            ->assertInertia(fn (Assert $page) => $page->has('pendingTechnicians', 0));
    }

    public function test_another_companys_manager_cannot_approve_an_application(): void
    {
        [$volt, $voltManager] = $this->company('Volt & Co');
        [$rival, $rivalManager] = $this->company('Rival Co');
        $jamie = $this->applicant($volt, 'Jamie');

        $this->actingAs($voltManager)->post(route('teams.store'), ['name' => 'Crew A']);
        $team = Team::withoutGlobalScopes()->where('company_id', $volt->id)->sole();

        $this->actingAs($rivalManager)
            ->post(route('technicians.approve', $jamie), ['team_id' => $team->id, 'role' => 'Foreman'])
            ->assertNotFound();
        $this->assertSame(User::STATUS_PENDING_APPROVAL, $jamie->fresh()->status);

        // Their own manager can — and the technician lands on that company's register.
        $this->actingAs($voltManager)
            ->post(route('technicians.approve', $jamie), ['team_id' => $team->id, 'role' => 'Foreman'])
            ->assertSessionHasNoErrors();
        $this->assertSame(User::STATUS_ACTIVE, $jamie->fresh()->status);
        $foreman = Foreman::withoutGlobalScopes()->where('user_id', $jamie->id)->sole();
        $this->assertSame($volt->id, $foreman->company_id);
        $this->assertSame($volt->id, TeamMember::withoutGlobalScopes()->where('user_id', $jamie->id)->sole()->company_id);
    }

    public function test_the_signup_screen_can_list_the_companies_to_apply_to(): void
    {
        $this->company('Volt & Co');
        $this->company('Alpha Electric');

        $this->getJson('/api/v1/companies')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Alpha Electric')
            ->assertJsonPath('data.1.name', 'Volt & Co')
            ->assertJsonMissingPath('data.0.email')
            ->assertJsonMissingPath('data.0.phone');
    }

    public function test_a_technician_cannot_apply_without_a_real_company(): void
    {
        $payload = ['name' => 'Jamie', 'email' => 'j@example.com', 'password' => 'correct-password', 'password_confirmation' => 'correct-password'];

        $this->postJson('/api/v1/auth/register', $payload)->assertStatus(422)->assertJsonValidationErrors('company_id');
        $this->postJson('/api/v1/auth/register', $payload + ['company_id' => 999])->assertStatus(422)->assertJsonValidationErrors('company_id');
        $this->assertSame(0, User::where('email', 'j@example.com')->count());
    }

    public function test_a_plan_counts_only_its_own_companys_people(): void
    {
        [$volt, $voltManager] = $this->company('Volt & Co');
        [$rival] = $this->company('Rival Co');
        User::factory()->count(3)->create(['status' => User::STATUS_ACTIVE, 'company_id' => $rival->id]);

        $this->actingAs($voltManager)->get('/settings')
            ->assertInertia(fn (Assert $page) => $page->where('subscription.seats.used', 1));
    }

    /** A job raised by a company's manager, with one task on it. */
    private function jobWithTask(User $manager, string $name): array
    {
        $job = Job::create(['user_id' => $manager->id, 'name' => $name, 'client' => 'Acme', 'status' => 'in-progress']);
        $schedule = app(ScheduleBuilder::class)->build($job, $manager, withTasks: false);
        $task = $schedule->tasks()->create([
            'job_id' => $job->id, 'title' => "{$name} task", 'status' => 'pending', 'position' => 0,
        ]);

        return [$job, $task];
    }

    public function test_a_managers_mobile_app_lists_only_their_companys_jobs_and_tasks(): void
    {
        [, $voltManager] = $this->company('Volt & Co');
        [, $rivalManager] = $this->company('Rival Co');
        [$voltJob] = $this->jobWithTask($voltManager, 'Volt Hall');
        [$rivalJob] = $this->jobWithTask($rivalManager, 'Rival Tower');

        $token = $voltManager->createToken('t')->plainTextToken;

        $ids = collect($this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/jobs')->assertOk()->json('data.jobs'))->pluck('id');
        $this->assertTrue($ids->contains($voltJob->id));
        $this->assertFalse($ids->contains($rivalJob->id));

        auth()->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/jobs/{$rivalJob->id}")->assertForbidden();

        auth()->forgetGuards();
        $titles = collect($this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/tasks')->assertOk()->json('data.tasks'))->pluck('title');
        $this->assertContains('Volt Hall task', $titles->all());
        $this->assertNotContains('Rival Tower task', $titles->all());
    }

    public function test_time_can_only_be_logged_on_a_job_of_your_own_company(): void
    {
        [, $voltManager] = $this->company('Volt & Co');
        [, $rivalManager] = $this->company('Rival Co');
        [$voltJob, $voltTask] = $this->jobWithTask($voltManager, 'Volt Hall');
        [$rivalJob, $rivalTask] = $this->jobWithTask($rivalManager, 'Rival Tower');

        // The job pickers and the task list of another company's job are closed.
        $this->actingAs($voltManager)->get(route('time-entries.index'))
            ->assertInertia(fn (Assert $page) => $page->has('jobs', 1)->where('jobs.0.name', 'Volt Hall'));
        $this->actingAs($voltManager)->getJson(route('time-entries.job-tasks', $rivalJob))->assertNotFound();
        $this->actingAs($voltManager)->getJson(route('time-entries.job-tasks', $voltJob))->assertOk()->assertJsonCount(1);

        // And so is starting a timer on it.
        $this->actingAs($voltManager)
            ->postJson(route('timer.start'), ['job_id' => $rivalJob->id, 'job_task_id' => $rivalTask->id])
            ->assertNotFound();
    }

    public function test_the_mobile_teams_screen_counts_only_their_companys_work(): void
    {
        [$volt, $voltManager] = $this->company('Volt & Co');
        [$rival, $rivalManager] = $this->company('Rival Co');
        [$voltJob, $voltTask] = $this->jobWithTask($voltManager, 'Volt Hall');
        [$rivalJob, $rivalTask] = $this->jobWithTask($rivalManager, 'Rival Tower');

        $dana = (new Foreman(['name' => 'Dana Wu', 'initials' => 'DW']))->forceFill(['company_id' => $volt->id]);
        $dana->save();
        $voltTask->update(['foreman_id' => $dana->id]);
        // Someone from another company's job cannot be on Dana's list.
        $rivalTask->update(['foreman_id' => $dana->id]);

        $token = $voltManager->createToken('t')->plainTextToken;
        $show = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/team/{$dana->id}")->assertOk();

        $this->assertSame(['Volt Hall task'], collect($show->json('data.tasks'))->pluck('title')->all());
    }

    /** A second manager in an existing company. */
    private function coManager(CompanyProfile $company): User
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $manager->forceFill(['company_id' => $company->id])->save();

        return $manager->fresh();
    }

    public function test_every_manager_of_a_company_sees_all_of_its_work(): void
    {
        [$volt, $first] = $this->company('Volt & Co');
        $second = $this->coManager($volt);
        [, $rivalManager] = $this->company('Rival Co');

        $project = Project::create(['user_id' => $first->id, 'name' => 'Volt Hall', 'client' => 'Acme', 'status' => 'draft']);
        [$job] = $this->jobWithTask($first, 'Volt Hall Job');

        // The second manager sees what the first made...
        $this->actingAs($second)->get(route('projects.show', $project))->assertOk();
        $this->actingAs($second)->get(route('jobs.show', $job))->assertOk();
        $this->actingAs($second)->get(route('jobs.index'))
            ->assertInertia(fn (Assert $page) => $page->has('jobs.data', 1)->where('jobs.data.0.name', 'Volt Hall Job'));
        $this->actingAs($second)->get(route('tasks.index'))
            ->assertInertia(fn (Assert $page) => $page->has('jobs.data', 1)->where('jobs.data.0.tasks.0.title', 'Volt Hall Job task'));
        $this->actingAs($second)->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('totalProjects', 1));

        // ...another company's manager sees none of it.
        $this->actingAs($rivalManager)->get(route('projects.show', $project))->assertForbidden();
        $this->actingAs($rivalManager)->get(route('jobs.show', $job))->assertForbidden();
        $this->actingAs($rivalManager)->get(route('jobs.index'))
            ->assertInertia(fn (Assert $page) => $page->has('jobs.data', 0));
        $this->actingAs($rivalManager)->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('totalProjects', 0));
    }

    public function test_an_account_with_no_company_still_sees_only_its_own_work(): void
    {
        $alone = User::factory()->create(['role' => 'Project Manager']);
        $other = User::factory()->create(['role' => 'Project Manager']);
        $job = Job::create(['user_id' => $other->id, 'name' => 'Not Yours', 'client' => 'Acme', 'status' => 'planning']);

        $this->actingAs($alone)->get(route('jobs.show', $job))->assertForbidden();
    }

    public function test_settings_shows_the_company_details_and_no_way_to_add_a_manager(): void
    {
        [$volt, $first] = $this->company('Volt & Co');
        $volt->update(['license_number' => 'TECL-123', 'timezone' => 'America/New_York']);
        $second = $this->coManager($volt);

        foreach ([$first, $second] as $manager) {
            $this->actingAs($manager)->get('/settings')
                ->assertInertia(fn (Assert $page) => $page
                    ->where('company.name', 'Volt & Co')
                    ->where('company.businessAddress', '1 Main St')
                    ->where('company.primaryContact', 'Alex')
                    ->where('company.phone', '(512) 555-0142')
                    ->where('company.email', 'o@x.test')
                    ->where('company.licenseNumber', 'TECL-123')
                    ->where('company.timezone', 'America/New York')
                    ->missing('managers')
                    ->missing('canAddManagers'));
        }

        // Adding a manager is Add Member's job now; the old Settings endpoint is gone.
        $this->actingAs($first)->post('/settings/payment/managers', [
            'name' => 'Riley Park', 'email' => 'riley@volt.test',
            'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x',
        ])->assertNotFound();
    }

    public function test_an_account_with_no_company_sees_no_company_details(): void
    {
        $alone = User::factory()->create(['role' => 'Project Manager']);

        $this->actingAs($alone)->get('/settings')->assertInertia(fn (Assert $page) => $page->where('company', null));
    }

    public function test_only_a_manager_with_a_company_can_add_a_manager_from_add_member(): void
    {
        [, $first] = $this->company('Volt & Co');
        $crew = User::factory()->create(['role' => 'Journeyman', 'company_id' => $first->company_id]);
        $payload = ['name' => 'Riley Park', 'role' => 'manager', 'email' => 'riley@volt.test', 'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x'];

        $this->actingAs($crew)->post(route('foremen.store'), $payload)->assertForbidden();
        $this->assertSame(0, User::where('email', 'riley@volt.test')->count());
    }

    public function test_time_is_read_only_within_the_company(): void
    {
        [$volt, $voltManager] = $this->company('Volt & Co');
        [$rival, $rivalManager] = $this->company('Rival Co');
        [$voltJob] = $this->jobWithTask($voltManager, 'Volt Hall');

        $tech = User::factory()->create(['role' => 'Journeyman', 'company_id' => $volt->id]);
        $entry = TimeEntry::create([
            'job_id' => $voltJob->id, 'user_id' => $tech->id, 'date' => '2026-09-17',
            'start_time' => '08:00:00', 'end_time' => '10:00:00', 'hours' => 2,
            'source' => TimeEntry::SOURCE_MANUAL, 'status' => TimeEntry::STATUS_SUBMITTED,
        ]);

        // The company's own manager can read and decide it.
        $this->actingAs($voltManager)->get(route('time-entries.show', $entry))->assertOk();
        $this->actingAs($voltManager)->post(route('time-entries.approve', $entry))->assertSessionHasNoErrors();

        // Another company's manager cannot even see that it exists.
        $this->actingAs($rivalManager)->get(route('time-entries.show', $entry))->assertNotFound();
        $this->actingAs($rivalManager)->post(route('time-entries.approve', $entry))->assertNotFound();
        $this->assertSame(0, TimeEntry::count());
        $this->actingAs($rivalManager)->get(route('time-entries.index'))
            ->assertInertia(fn (Assert $page) => $page->has('log.data', 0));
    }

    public function test_add_member_can_add_a_manager_with_email_and_password(): void
    {
        [$volt, $first] = $this->company('Volt & Co');

        $this->actingAs($first)->get(route('foremen.create'))
            ->assertInertia(fn (Assert $page) => $page->where('roles', fn ($roles) => collect($roles)->pluck('value')->contains('manager')));

        $this->actingAs($first)->post(route('foremen.store'), [
            'name' => 'Riley Park', 'role' => 'manager', 'email' => 'riley@volt.test',
            'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x',
        ])->assertSessionHasNoErrors()->assertRedirect(route('teams.index'));

        $riley = User::where('email', 'riley@volt.test')->sole();
        $this->assertSame('Project Manager', $riley->role);
        $this->assertSame($volt->id, $riley->company_id);
        // A manager is not crew: nothing lands on the register.
        $this->assertSame(0, Foreman::withoutGlobalScopes()->count());

        // And they see the company's work straight away.
        $job = Job::create(['user_id' => $first->id, 'name' => 'Volt Hall', 'client' => 'Acme', 'status' => 'planning']);
        $this->actingAs($riley)->get(route('jobs.show', $job))->assertOk();
    }

    public function test_a_manager_needs_an_email_and_password_like_any_member(): void
    {
        [, $first] = $this->company('Volt & Co');

        $this->actingAs($first)->post(route('foremen.store'), ['name' => 'Riley Park', 'role' => 'manager'])
            ->assertSessionHasErrors(['email', 'password']);
        $this->assertSame(1, User::count());
    }

    public function test_manager_is_not_offered_to_someone_with_no_company(): void
    {
        $alone = User::factory()->create(['role' => 'Project Manager']);

        $this->actingAs($alone)->get(route('foremen.create'))
            ->assertInertia(fn (Assert $page) => $page->where('roles', fn ($roles) => ! collect($roles)->pluck('value')->contains('manager')));
        $this->actingAs($alone)->post(route('foremen.store'), [
            'name' => 'Riley Park', 'role' => 'manager', 'email' => 'riley@volt.test',
            'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x',
        ])->assertSessionHasErrors('role');
    }

    public function test_a_full_plan_cannot_take_another_manager(): void
    {
        [$volt, $first] = $this->company('Volt & Co');
        Subscription::create([
            'user_id' => $first->id, 'plan' => 'starter', 'status' => 'active', 'billing_cycle' => 'monthly', 'renews_on' => now()->addMonth(),
        ]);
        $this->coManager($volt);   // Starter holds two: this is the second.

        $this->actingAs($first)->post(route('foremen.store'), [
            'name' => 'Riley Park', 'role' => 'manager', 'email' => 'riley@volt.test',
            'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x',
        ])->assertSessionHasErrors('email');
        $this->assertSame(0, User::where('email', 'riley@volt.test')->count());
    }

    public function test_only_the_owner_sees_the_managers_of_the_company(): void
    {
        [$volt, $owner] = $this->company('Volt & Co');
        $second = $this->coManager($volt);
        [, $rivalOwner] = $this->company('Rival Co');
        $crew = User::factory()->create(['role' => 'Journeyman', 'company_id' => $volt->id]);
        $this->grantPermissions($crew, ['crew.view']); // Crew do not open Teams by default; this one was given it.

        $this->actingAs($owner)->get(route('teams.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canSeeManagers', true)
                ->has('managers', 2)
                ->where('managers.0.id', $owner->id)
                ->where('managers.0.isOwner', true)
                ->where('managers.0.isYou', true)
                ->where('managers.1.id', $second->id)
                ->where('managers.1.isOwner', false)
                ->where('managers.1.canEdit', true)
                ->where('managers.1.canEditEmail', true)
                ->where('managers.0.canEditEmail', false));

        // A co-manager, another company's owner and crew do not get the tab or the list.
        $this->actingAs($second)->get(route('teams.index'))
            ->assertInertia(fn (Assert $page) => $page->where('canSeeManagers', false)->has('managers', 0));
        $this->actingAs($crew)->get(route('teams.index'))
            ->assertInertia(fn (Assert $page) => $page->where('canSeeManagers', false)->has('managers', 0));
        $this->actingAs($rivalOwner)->get(route('teams.index'))
            ->assertInertia(fn (Assert $page) => $page->has('managers', 1)->where('managers.0.id', $rivalOwner->id));
    }

    public function test_only_the_owner_can_correct_a_manager(): void
    {
        [$volt, $owner] = $this->company('Volt & Co');
        $second = $this->coManager($volt);
        $third = $this->coManager($volt);
        [, $rivalOwner] = $this->company('Rival Co');

        // The owner opens the same Edit Member form, for a manager...
        $this->actingAs($owner)->get(route('managers.edit', $second))
            ->assertInertia(fn (Assert $page) => $page
                ->component('ForemanEdit')
                ->where('foreman.name', $second->name)
                ->where('foreman.role', 'manager')
                ->where('manager.canEditEmail', true)
                ->where('manager.saveUrl', "/settings/payment/managers/{$second->id}")
                ->where('manager.backUrl', '/teams?tab=managers'));

        // ...and corrects someone else's name, phone and email.
        $this->actingAs($owner)->put("/settings/payment/managers/{$second->id}", [
            'name' => 'Riley Park', 'phone' => '(512) 555-0199', 'email' => 'Riley.Park@Volt.test',
        ])->assertSessionHasNoErrors()->assertSessionHas('success')->assertRedirect(route('teams.index', ['tab' => 'managers']));
        $second->refresh();
        $this->assertSame('Riley Park', $second->name);
        $this->assertSame('(512) 555-0199', $second->phone);
        $this->assertSame('riley.park@volt.test', $second->email);

        // The owner can correct their own name and phone, but not their own sign-in email.
        $this->actingAs($owner)->get(route('managers.edit', $owner))
            ->assertInertia(fn (Assert $page) => $page->where('manager.canEditEmail', false));
        $this->actingAs($owner)->put("/settings/payment/managers/{$owner->id}", ['name' => 'Owner Renamed', 'phone' => ''])
            ->assertSessionHasNoErrors();
        $this->assertSame('Owner Renamed', $owner->fresh()->name);
        $this->actingAs($owner)->put("/settings/payment/managers/{$owner->id}", ['name' => 'Owner Renamed', 'email' => 'new@volt.test'])
            ->assertSessionHasErrors('email');

        // No other manager can open or change anyone's details — not even their own.
        foreach ([$second, $third] as $manager) {
            $this->actingAs($manager)->get(route('managers.edit', $third))->assertForbidden();
            $this->actingAs($manager)->get(route('managers.edit', $manager))->assertForbidden();
            $this->actingAs($manager)->put("/settings/payment/managers/{$manager->id}", ['name' => 'Self Edit', 'email' => 'x@volt.test'])
                ->assertForbidden();
        }
        $this->assertNotSame('Self Edit', $second->fresh()->name);
        $this->assertNotSame('Self Edit', $third->fresh()->name);

        // Another company's owner cannot reach them at all, and an email cannot be taken.
        $this->actingAs($rivalOwner)->get(route('managers.edit', $second))->assertNotFound();
        $this->actingAs($rivalOwner)->put("/settings/payment/managers/{$second->id}", ['name' => 'Hijacked', 'email' => 'x@rival.test'])
            ->assertNotFound();
        $this->actingAs($owner)->put("/settings/payment/managers/{$third->id}", ['name' => 'Third', 'email' => $owner->email])
            ->assertSessionHasErrors('email');
    }

    public function test_a_crew_member_is_not_a_manager_to_edit(): void
    {
        [$volt, $owner] = $this->company('Volt & Co');
        $crew = User::factory()->create(['role' => 'Journeyman', 'company_id' => $volt->id]);

        $this->actingAs($owner)->get(route('managers.edit', $crew))->assertNotFound();
        $this->actingAs($owner)->put("/settings/payment/managers/{$crew->id}", ['name' => 'Nope', 'email' => 'n@volt.test'])
            ->assertNotFound();
    }

    public function test_only_the_owner_can_edit_the_company_details(): void
    {
        Storage::fake('public');
        [$volt, $owner] = $this->company('Volt & Co');
        $second = $this->coManager($volt);
        [, $rivalOwner] = $this->company('Rival Co');

        // The owner is offered the edit; other managers only read.
        $this->actingAs($owner)->get('/settings')->assertInertia(fn (Assert $page) => $page->where('canEditCompany', true));
        $this->actingAs($second)->get('/settings')->assertInertia(fn (Assert $page) => $page->where('canEditCompany', false));

        // The same form as setup, filled with what is on file.
        $this->actingAs($owner)->get(route('settings.company.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('CompanySetup')
                ->where('company.name', 'Volt & Co')
                ->where('editing.saveUrl', '/settings/company')
                ->where('editing.backUrl', '/settings?tab=company'));

        $change = [
            'name' => 'Volt Electric', 'business_address' => '99 New Rd', 'primary_contact' => 'Sam Lee',
            'phone' => '(512) 555-0100', 'email' => 'hello@volt.test', 'license_number' => 'TECL-9', 'timezone' => 'America/Denver',
            'logo' => UploadedFile::fake()->image('logo.png', 100, 100),
        ];

        // Nobody else can open or change it.
        $this->actingAs($second)->get(route('settings.company.edit'))->assertForbidden();
        $this->actingAs($second)->put(route('settings.company.update'), $change)->assertForbidden();
        $this->actingAs($rivalOwner)->put(route('settings.company.update'), [...$change, 'name' => 'Hijacked'])->assertRedirect();
        $this->assertSame('Volt & Co', $volt->fresh()->name);   // the rival edited only their own

        // The owner's change is saved — and seen by every manager.
        $this->actingAs($owner)->put(route('settings.company.update'), $change)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.payment.index', ['tab' => 'company']));

        $volt->refresh();
        $this->assertSame('Volt Electric', $volt->name);
        $this->assertSame('America/Denver', $volt->timezone);
        $this->assertSame($owner->id, $volt->user_id);
        Storage::disk('public')->assertExists($volt->logo_path);
        $this->actingAs($second)->get('/settings')->assertInertia(fn (Assert $page) => $page
            ->where('company.name', 'Volt Electric')->where('company.timezone', 'America/Denver'));

        // Every required field is still required, and the logo can be taken away.
        $this->actingAs($owner)->put(route('settings.company.update'), [])->assertSessionHasErrors(['name', 'business_address']);
        $oldLogo = $volt->logo_path;
        $this->actingAs($owner)->put(route('settings.company.update'), [...$change, 'logo' => null, 'remove_logo' => true])->assertSessionHasNoErrors();
        $this->assertNull($volt->fresh()->logo_path);
        Storage::disk('public')->assertMissing($oldLogo);
    }

    public function test_only_the_owner_can_add_a_manager(): void
    {
        [$volt, $owner] = $this->company('Volt & Co');
        $second = $this->coManager($volt);
        $payload = ['name' => 'Riley Park', 'role' => 'manager', 'email' => 'riley@volt.test', 'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x'];

        // A co-manager is not offered the role, and cannot post it.
        $this->actingAs($second)->get(route('foremen.create'))
            ->assertInertia(fn (Assert $page) => $page->where('roles', fn ($roles) => ! collect($roles)->pluck('value')->contains('manager')));
        $this->actingAs($second)->post(route('foremen.store'), $payload)->assertSessionHasErrors('role');
        $this->assertSame(0, User::where('email', 'riley@volt.test')->count());

        // The owner is, and can.
        $this->actingAs($owner)->get(route('foremen.create'))
            ->assertInertia(fn (Assert $page) => $page->where('roles', fn ($roles) => collect($roles)->pluck('value')->contains('manager')));
        $this->actingAs($owner)->post(route('foremen.store'), $payload)->assertSessionHasNoErrors();
        $this->assertSame($volt->id, User::where('email', 'riley@volt.test')->sole()->company_id);
    }

    public function test_only_the_owner_can_edit_company_details_from_every_side(): void
    {
        [$volt, $owner] = $this->company('Volt & Co');
        $second = $this->coManager($volt);

        $this->actingAs($second)->get('/settings')->assertInertia(fn (Assert $page) => $page->where('canEditCompany', false));
        $this->actingAs($second)->get(route('settings.company.edit'))->assertForbidden();
        $this->actingAs($second)->put(route('settings.company.update'), ['name' => 'Hijacked'])->assertForbidden();
        $this->assertSame('Volt & Co', $volt->fresh()->name);
        $this->actingAs($owner)->get('/settings')->assertInertia(fn (Assert $page) => $page->where('canEditCompany', true));
    }

    public function test_every_page_shares_the_companys_name_and_logo_or_none_yet(): void
    {
        [$volt, $owner] = $this->company('Volt & Co');
        $second = $this->coManager($volt);
        $alone = User::factory()->create(['role' => 'Project Manager']);

        // No logo uploaded yet: the name is shared and the logo is null, so the header shows an icon.
        $this->actingAs($owner)->get('/settings')
            ->assertInertia(fn (Assert $page) => $page->where('company.name', 'Volt & Co')->where('company.logoUrl', null));

        // Once uploaded, every manager of the company gets the same logo, as a path on this site.
        $volt->update(['logo_path' => 'company/logo.png']);
        foreach ([$owner, $second] as $manager) {
            $this->actingAs($manager)->get('/settings')
                ->assertInertia(fn (Assert $page) => $page->where('company.logoUrl', '/storage/company/logo.png'));
        }
        $this->actingAs($owner)->get(route('teams.index'))
            ->assertInertia(fn (Assert $page) => $page->where('company.logoUrl', '/storage/company/logo.png'));

        // Someone with no company has nothing to show.
        $this->actingAs($alone)->get('/settings')->assertInertia(fn (Assert $page) => $page->where('company', null));
    }

    public function test_a_team_can_be_renamed(): void
    {
        [$volt, $owner] = $this->company('Volt & Co');
        [, $rivalOwner] = $this->company('Rival Co');
        $this->actingAs($owner)->post(route('teams.store'), ['name' => 'Crew A']);
        $this->actingAs($owner)->post(route('teams.store'), ['name' => 'Crew B']);
        $a = Team::withoutGlobalScopes()->where('name', 'Crew A')->sole();

        $this->actingAs($owner)->put(route('teams.update', $a), ['name' => 'North Crew'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('North Crew', $a->fresh()->name);

        // Keeping its own name is fine; taking another crew's is not; a blank is not.
        $this->actingAs($owner)->put(route('teams.update', $a), ['name' => 'North Crew'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->put(route('teams.update', $a), ['name' => 'Crew B'])->assertSessionHasErrors('name');
        $this->actingAs($owner)->put(route('teams.update', $a), ['name' => ''])->assertSessionHasErrors('name');

        // Another company's crew is out of reach — and can share a name with ours.
        $this->actingAs($rivalOwner)->put(route('teams.update', $a), ['name' => 'Hijacked'])->assertNotFound();
        $this->actingAs($rivalOwner)->post(route('teams.store'), ['name' => 'North Crew'])->assertSessionHasNoErrors();
    }

    public function test_only_an_empty_team_can_be_deleted_and_the_work_it_was_on_is_kept(): void
    {
        [$volt, $owner] = $this->company('Volt & Co');
        [, $rivalOwner] = $this->company('Rival Co');
        foreach (['Empty Crew', 'Staffed Crew', 'Used Crew'] as $name) {
            $this->actingAs($owner)->post(route('teams.store'), ['name' => $name]);
        }
        [$empty, $staffed, $used] = array_map(fn ($name) => Team::withoutGlobalScopes()->where('name', $name)->sole(), ['Empty Crew', 'Staffed Crew', 'Used Crew']);

        (new Foreman(['name' => 'Dana Wu', 'initials' => 'DW', 'team_id' => $staffed->id]))->forceFill(['company_id' => $volt->id])->save();
        $job = Job::create(['user_id' => $owner->id, 'name' => 'Volt Hall', 'client' => 'Acme', 'status' => 'planning']);
        $job->forceFill(['team_id' => $used->id])->save();

        // With people on it, it stays — and the reason is given.
        $this->actingAs($owner)->delete(route('teams.destroy', $staffed))->assertSessionHas('warning', fn ($m) => str_contains($m, 'still has people'));
        $this->assertNotNull($staffed->fresh());
        $this->assertSame($staffed->id, Foreman::withoutGlobalScopes()->where('name', 'Dana Wu')->sole()->team_id);

        // Another company cannot touch it, and a crew member cannot delete anything.
        $this->actingAs($rivalOwner)->delete(route('teams.destroy', $empty))->assertNotFound();
        $crew = User::factory()->create(['role' => 'Apprentice', 'company_id' => $volt->id]);
        $this->actingAs($crew)->delete(route('teams.destroy', $empty))->assertForbidden();
        $this->assertNotNull($empty->fresh());

        // Empty, it goes.
        $this->actingAs($owner)->delete(route('teams.destroy', $empty))->assertSessionHas('success');
        $this->assertNull(Team::withoutGlobalScopes()->find($empty->id));

        // An empty crew that a job still names is deleted too; the job is kept and just stops naming a crew.
        $this->actingAs($owner)->delete(route('teams.destroy', $used))
            ->assertSessionHas('success', fn ($m) => str_contains($m, '1 job that had this crew now have none'));
        $this->assertNull(Team::withoutGlobalScopes()->find($used->id));
        $this->assertNull($job->fresh()->team_id);
        $this->assertNotNull($job->fresh());
    }
}
