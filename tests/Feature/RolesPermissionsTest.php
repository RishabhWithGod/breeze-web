<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Client;
use App\Models\CompanyProfile;
use App\Models\Job;
use App\Models\User;
use App\Services\Access\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Roles & Permissions: what each role may open and do on the web app, per company.
 */
class RolesPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private CompanyProfile $company;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'Project Manager']);
        $this->company = CompanyProfile::create([
            'user_id' => $this->manager->id, 'name' => 'Volt & Co', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        $this->manager->forceFill(['company_id' => $this->company->id])->save();
        $this->company->forceFill(['onboarding_finished_at' => now()])->save();
        $this->manager = $this->manager->fresh();
    }

    private function crew(string $role): User
    {
        return User::factory()->create(['role' => $role, 'company_id' => $this->company->id]);
    }

    private function permissions(): Permissions
    {
        return app(Permissions::class);
    }

    public function test_a_company_starts_with_the_default_permissions(): void
    {
        $matrix = $this->permissions()->matrix($this->company->id);

        // Manager: everything.
        $this->assertEqualsCanonicalizing($this->permissions()->all(), $matrix['manager']);
        // Foreman: everything except AI Takeoff and company setup.
        $this->assertEqualsCanonicalizing(
            array_values(array_filter($this->permissions()->all(), fn ($key) => ! str_starts_with($key, 'takeoff.') && ! str_starts_with($key, 'admin.'))),
            $matrix['foreman'],
        );
        // Journeyman: Jobs and Tasks, read only. Apprentice: Tasks, read only.
        $this->assertEqualsCanonicalizing(['jobs.view', 'tasks.view'], $matrix['journeyman']);
        $this->assertSame(['tasks.view'], $matrix['apprentice']);
    }

    public function test_each_role_reaches_only_what_its_permissions_allow(): void
    {
        $foreman = $this->crew('Foreman');
        foreach (['clients.index', 'projects.index', 'estimates.index', 'jobs.index', 'tasks.index', 'time-tracking.index', 'invoices.index', 'teams.index'] as $route) {
            $response = $this->actingAs($foreman)->get(route($route));
            $this->assertTrue($response->isSuccessful(), "{$route} gave {$response->status()}");
        }
        foreach (['takeoffs.index', 'roles.show', 'settings.payment.index'] as $route) {
            $this->actingAs($foreman)->get(route($route))->assertForbidden();
        }
        $this->actingAs($foreman)->get(route('team-setup.show'))->assertForbidden();

        $journeyman = $this->crew('Journeyman');
        $this->actingAs($journeyman)->get(route('jobs.index'))->assertOk();
        $this->actingAs($journeyman)->get(route('tasks.index'))->assertOk();
        foreach (['clients.index', 'estimates.index', 'invoices.index', 'scheduling.index', 'time-tracking.index', 'takeoffs.index'] as $route) {
            $this->actingAs($journeyman)->get(route($route))->assertForbidden();
        }

        $apprentice = $this->crew('Apprentice');
        $this->actingAs($apprentice)->get(route('tasks.index'))->assertOk();
        $this->actingAs($apprentice)->get(route('jobs.index'))->assertForbidden();

        foreach (['clients.index', 'takeoffs.index', 'roles.show', 'settings.payment.index', 'jobs.index'] as $route) {
            $this->actingAs($this->manager)->get(route($route))->assertOk();
        }
        // The dashboard is open to everyone signed in.
        $this->actingAs($apprentice)->get(route('home'))->assertOk();
    }

    public function test_read_only_means_nothing_can_be_created_edited_or_deleted(): void
    {
        $journeyman = $this->crew('Journeyman');

        $this->actingAs($journeyman)->get(route('jobs.create'))->assertForbidden();
        $this->actingAs($journeyman)->post(route('jobs.store'), [])->assertForbidden();
        $job = Job::create(['user_id' => $this->manager->id, 'name' => 'J', 'client' => 'C', 'status' => 'planning']);
        $this->actingAs($journeyman)->delete(route('jobs.destroy', $job))->assertForbidden();
        $this->assertNotNull($job->fresh());
        $this->actingAs($journeyman)->post(route('clients.store'), [])->assertForbidden();
    }

    public function test_the_manager_changes_them_and_it_applies_to_everyone_with_the_role(): void
    {
        $matrix = $this->permissions()->matrix($this->company->id);
        $matrix['journeyman'] = [...$matrix['journeyman'], 'clients.view', 'jobs.create'];
        $matrix['foreman'] = array_values(array_diff($matrix['foreman'], ['estimates.view', 'estimates.create', 'estimates.edit', 'estimates.delete']));

        $this->actingAs($this->manager)->put(route('roles.update'), ['granted' => ['foreman' => $matrix['foreman'], 'journeyman' => $matrix['journeyman'], 'apprentice' => $matrix['apprentice']]])
            ->assertSessionHasNoErrors();

        $first = $this->crew('Journeyman');
        $second = $this->crew('Journeyman');
        foreach ([$first, $second] as $journeyman) {
            $this->actingAs($journeyman)->get(route('clients.index'))->assertOk();
            $this->actingAs($journeyman)->get(route('jobs.create'))->assertOk();
        }
        $this->actingAs($first)->get(route('clients.create'))->assertForbidden();
        $this->actingAs($this->crew('Foreman'))->get(route('estimates.index'))->assertForbidden();

        // The page shows what was saved.
        $this->actingAs($this->manager)->get(route('roles.show'))->assertInertia(fn (Assert $page) => $page
            ->component('RolesPermissions')->where('customised', true)->has('granted.journeyman'));
    }

    public function test_a_managers_own_access_cannot_be_taken_away_and_choices_are_checked(): void
    {
        // Sent without the manager, or with its permissions cut, the manager still has everything.
        $this->actingAs($this->manager)->put(route('roles.update'), ['granted' => ['foreman' => [], 'journeyman' => [], 'apprentice' => [], 'manager' => []]])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing($this->permissions()->all(), $this->permissions()->matrix($this->company->id)['manager']);
        $this->actingAs($this->manager)->get(route('roles.show'))->assertOk();
        $this->actingAs($this->crew('Foreman'))->get(route('clients.index'))->assertForbidden();

        $this->actingAs($this->manager)->put(route('roles.update'), ['granted' => ['foreman' => ['not.a.permission'], 'journeyman' => [], 'apprentice' => []]])
            ->assertSessionHasErrors();
        $this->actingAs($this->manager)->put(route('roles.update'), ['granted' => ['foreman' => []]])->assertSessionHasErrors(['granted.journeyman', 'granted.apprentice']);

        // A module's actions mean nothing without its View: they are dropped.
        $this->actingAs($this->manager)->put(route('roles.update'), ['granted' => ['foreman' => ['clients.create'], 'journeyman' => ['jobs.view', 'jobs.edit'], 'apprentice' => []]]);
        $matrix = $this->permissions()->matrix($this->company->id);
        $this->assertSame([], $matrix['foreman']);
        $this->assertEqualsCanonicalizing(['jobs.view', 'jobs.edit'], $matrix['journeyman']);
    }

    public function test_only_someone_who_manages_roles_can_change_them_and_each_company_has_its_own(): void
    {
        $foreman = $this->crew('Foreman');
        $this->actingAs($foreman)->put(route('roles.update'), ['granted' => ['foreman' => [], 'journeyman' => [], 'apprentice' => []]])->assertForbidden();
        $this->permissions()->matrix($this->company->id);

        // Giving a foreman Manage roles lets them in.
        $this->grantPermissions($foreman, ['admin.manage_roles']);
        $this->actingAs($foreman->fresh())->get(route('roles.show'))->assertOk();

        // Another company is untouched by any of it.
        $other = User::factory()->create(['role' => 'Project Manager']);
        $theirs = CompanyProfile::create([
            'user_id' => $other->id, 'name' => 'Other Co', 'business_address' => '2 Main St', 'primary_contact' => 'B',
            'phone' => '(512) 555-0143', 'email' => 'p@x.test', 'timezone' => 'America/Chicago',
        ]);
        $this->assertSame(['tasks.view'], $this->permissions()->matrix($theirs->id)['apprentice']);
        $this->assertNotContains('admin.manage_roles', $this->permissions()->matrix($theirs->id)['foreman']);
    }

    public function test_a_role_outside_the_matrix_keeps_the_access_it_had(): void
    {
        $estimator = $this->crew('Estimator');

        $this->assertNull($this->permissions()->for($estimator));
        $this->actingAs($estimator)->get(route('estimates.index'))->assertOk();
        $this->actingAs($estimator)->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('permissions', null));
    }

    public function test_the_menu_is_told_what_the_role_holds(): void
    {
        $this->actingAs($this->crew('Apprentice'))->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('permissions', ['tasks.view']));
        $this->actingAs($this->manager)->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('permissions', count($this->permissions()->all())));
    }

    public function test_a_request_is_classified_by_what_it_does(): void
    {
        $classify = function (string $name, string $method): ?string {
            $route = new Route([$method], '/x', []);
            $route->name($name);

            return $this->permissions()->required($route, $method);
        };

        $this->assertSame('clients.view', $classify('clients.index', 'GET'));
        $this->assertSame('clients.create', $classify('clients.create', 'GET'));
        $this->assertSame('clients.create', $classify('clients.store', 'POST'));
        $this->assertSame('clients.edit', $classify('clients.update', 'PUT'));
        $this->assertSame('clients.delete', $classify('clients.destroy', 'DELETE'));
        // Something that belongs to a record is an edit of it.
        $this->assertSame('jobs.edit', $classify('jobs.notes.store', 'POST'));
        $this->assertSame('jobs.edit', $classify('jobs.notes.destroy', 'DELETE'));
        // A job's tasks are the Tasks module.
        $this->assertSame('tasks.create', $classify('jobs.tasks.store', 'POST'));
        $this->assertSame('tasks.create', $classify('jobs.tasks.setup', 'GET'));
        $this->assertSame('schedule.view', $classify('scheduling.calendar', 'GET'));
        $this->assertSame('schedule.manage', $classify('scheduling.store', 'POST'));
        $this->assertSame('takeoff.create', $classify('projects.takeoff.start', 'POST'));
        $this->assertSame('takeoff.view', $classify('takeoffs.index', 'GET'));
        $this->assertSame('admin.system_settings', $classify('settings.payment.index', 'GET'));
        $this->assertSame('admin.manage_users', $classify('team-setup.invitations.store', 'POST'));
        // Not governed: the dashboard, a person's own profile, notifications.
        $this->assertNull($classify('home', 'GET'));
        $this->assertNull($classify('profile.update', 'PUT'));
        $this->assertNull($classify('notifications.index', 'GET'));
    }

    public function test_someone_who_may_not_do_something_is_told_so_instead_of_seeing_an_error(): void
    {
        $journeyman = $this->crew('Journeyman');
        $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];

        // Clicking through the app: they stay where they were, with a message.
        $this->actingAs($journeyman)->withHeaders([...$inertia, 'Referer' => route('jobs.index')])->get(route('clients.index'))
            ->assertRedirect(route('jobs.index'))
            ->assertSessionHas('denied', fn ($message) => str_contains($message, 'Clients') && str_contains($message, 'Ask a manager'));

        // Submitting something they may not: same, and nothing is saved.
        $this->actingAs($journeyman)->withHeaders([...$inertia, 'Referer' => route('jobs.index')])->post(route('clients.store'), ['name' => 'X'])
            ->assertRedirect(route('jobs.index'))->assertSessionHas('denied');
        $this->assertSame(0, Client::withoutGlobalScopes()->count());

        $this->flushHeaders();

        // A page opened directly shows a plain "no access" screen, never a server error.
        $this->actingAs($journeyman)->get(route('clients.index'))->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('NoAccess')->where('message', fn ($m) => str_contains($m, 'Clients')));

        // The notice is shared with whichever page the person lands on.
        $this->actingAs($journeyman)->withSession(['denied' => 'No.'])->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page->where('flash.denied', 'No.'));
    }
}
