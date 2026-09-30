<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CompanyProfile;
use App\Models\Foreman;
use App\Models\PriceBookItem;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Get Started: the checklist a company works through after it subscribes.
 */
class GetStartedTest extends TestCase
{
    use RefreshDatabase;

    private CompanyProfile $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'Project Manager']);
        $this->company = CompanyProfile::create([
            'user_id' => $this->owner->id, 'name' => 'Volt & Co', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        $this->owner->forceFill(['company_id' => $this->company->id])->save();
        $this->owner = $this->owner->fresh();
    }

    private function price(?int $userId, string $key): PriceBookItem
    {
        return PriceBookItem::create(['user_id' => $userId, 'match_key' => $key, 'unit' => 'EA', 'description' => $key, 'section' => 'X', 'unit_material_cost' => 1, 'unit_manhours' => 1]);
    }

    private function open()
    {
        return $this->actingAs($this->owner)->get(route('get-started.show'));
    }

    /** @return array<string, string> step key => status */
    private function statuses(): array
    {
        $statuses = [];
        $this->open()->assertInertia(function (Assert $page) use (&$statuses) {
            $statuses = collect($page->toArray()['props']['checklist']['steps'])->pluck('status', 'key')->all();
        });

        return $statuses;
    }

    public function test_a_new_company_has_only_its_profile_done_and_the_project_locked(): void
    {
        $this->open()->assertInertia(fn (Assert $page) => $page
            ->component('GetStarted')
            ->where('checklist.completed', 1)
            ->where('checklist.total', 5)
            ->where('checklist.percent', 20)
            ->where('checklist.next', 'team')
            ->where('checklist.finished', false)
            ->where('checklist.canFinish', false)
            ->where('checklist.steps.0.key', 'company')
            ->where('checklist.steps.0.status', 'completed')
            ->where('checklist.steps.1.title', 'Invite Team')
            ->where('checklist.steps.1.status', 'pending')
            ->where('checklist.steps.1.skippable', true)
            ->where('checklist.steps.1.href', '/team-setup')
            ->where('checklist.steps.2.title', 'Configure Commodity List')
            ->where('checklist.steps.2.href', '/commodities')
            ->where('checklist.steps.3.title', 'Create First Client')
            ->where('checklist.steps.3.skippable', false)
            ->where('checklist.steps.4.title', 'Create First Project')
            ->where('checklist.steps.4.status', 'locked')
            ->where('checklist.steps.4.lockedBecause', 'Add your first client first.'));
    }

    public function test_progress_follows_the_work_wherever_it_is_done(): void
    {
        // A crew member on the register is the team started.
        (new Foreman(['name' => 'Dana Wu', 'initials' => 'DW']))->forceFill(['company_id' => $this->company->id])->save();
        $this->assertSame('completed', $this->statuses()['team']);

        // The company's own price list is the commodity list — the shared one everybody falls back to is not,
        // and an archived item no longer counts.
        $this->price(null, 'shared');
        $this->assertSame('pending', $this->statuses()['commodities']);
        $own = $this->price($this->owner->id, 'own');
        $own->forceFill(['archived_at' => now()])->save();
        $this->assertSame('pending', $this->statuses()['commodities']);
        $own->forceFill(['archived_at' => null])->save();
        $this->assertSame('completed', $this->statuses()['commodities']);

        // A client unlocks the project; a project completes it.
        $client = $this->owner->clients()->create(['name' => 'Acme']);
        $statuses = $this->statuses();
        $this->assertSame('completed', $statuses['client']);
        $this->assertSame('pending', $statuses['project']);

        Project::create(['user_id' => $this->owner->id, 'client_id' => $client->id, 'name' => 'P', 'client' => 'Acme', 'status' => 'draft']);
        $this->open()->assertInertia(fn (Assert $page) => $page
            ->where('checklist.completed', 5)
            ->where('checklist.percent', 100)
            ->where('checklist.next', null)
            ->where('checklist.canFinish', true));
    }

    public function test_a_colleague_added_to_the_company_starts_the_team_too(): void
    {
        $this->assertSame('pending', $this->statuses()['team']);

        User::factory()->create(['role' => 'Journeyman', 'company_id' => $this->company->id]);

        $this->assertSame('completed', $this->statuses()['team']);
    }

    public function test_continue_setup_goes_to_the_first_step_still_to_do(): void
    {
        $order = fn () => $this->open()->assertInertia(fn (Assert $page) => $page->where('checklist.next', fn ($next) => true));

        $next = function (): ?string {
            $key = null;
            $this->open()->assertInertia(function (Assert $page) use (&$key) {
                $key = $page->toArray()['props']['checklist']['next'];
            });

            return $key;
        };

        $this->assertSame('team', $next());

        // Skipping the team moves on to the price list, then the client.
        $this->actingAs($this->owner)->post(route('get-started.skip', 'team'))->assertRedirect();
        $this->assertSame('commodities', $next());
        $this->actingAs($this->owner)->post(route('get-started.skip', 'commodities'))->assertRedirect();
        $this->assertSame('client', $next());

        // The skipped steps show as skipped, and can be brought back.
        $statuses = $this->statuses();
        $this->assertSame('skipped', $statuses['team']);
        $this->assertSame('skipped', $statuses['commodities']);
        $this->actingAs($this->owner)->post(route('get-started.skip', 'team'), ['undo' => true]);
        $this->assertSame('pending', $this->statuses()['team']);
        $this->assertSame('team', $next());

        unset($order);
    }

    public function test_only_the_optional_steps_can_be_skipped(): void
    {
        foreach (['company', 'client', 'project', 'nonsense'] as $step) {
            $this->actingAs($this->owner)->post(route('get-started.skip', $step))->assertNotFound();
        }

        $this->assertNull($this->company->fresh()->onboarding_skipped);
    }

    public function test_setup_can_only_be_finished_once_the_required_steps_are_done(): void
    {
        // Skipping the optional ones is not enough.
        $this->actingAs($this->owner)->post(route('get-started.skip', 'team'));
        $this->actingAs($this->owner)->post(route('get-started.skip', 'commodities'));
        $this->actingAs($this->owner)->post(route('get-started.finish'))->assertSessionHas('warning');
        $this->assertNull($this->company->fresh()->onboarding_finished_at);

        $client = $this->owner->clients()->create(['name' => 'Acme']);
        Project::create(['user_id' => $this->owner->id, 'client_id' => $client->id, 'name' => 'P', 'client' => 'Acme', 'status' => 'draft']);

        // Now the two optional ones were skipped and it can be finished.
        $this->open()->assertInertia(fn (Assert $page) => $page->where('checklist.canFinish', true)->where('checklist.next', null));
        $this->actingAs($this->owner)->post(route('get-started.finish'))->assertRedirect(route('home'))->assertSessionHas('success');

        $this->assertNotNull($this->company->fresh()->onboarding_finished_at);
        $this->open()->assertInertia(fn (Assert $page) => $page->where('checklist.finished', true));
    }

    public function test_the_dashboard_is_the_checklist_until_setup_is_done(): void
    {
        // The managers of a company see the checklist where the dashboard would be.
        foreach ([$this->owner, User::factory()->create(['role' => 'Project Manager', 'company_id' => $this->company->id])] as $manager) {
            $this->actingAs($manager)->get('/home')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('GetStarted')->where('checklist.completed', fn ($done) => $done >= 1));
            $this->actingAs($manager)->get('/')->assertInertia(fn (Assert $page) => $page->component('GetStarted'));
        }

        // An estimator, crew and an account with no company get the dashboard as always.
        foreach (['Estimator', 'Journeyman', 'Foreman'] as $role) {
            $user = User::factory()->create(['role' => $role, 'company_id' => $this->company->id]);
            $this->actingAs($user)->get('/home')->assertInertia(fn (Assert $page) => $page->component('Home'));
        }
        $this->actingAs(User::factory()->create(['role' => 'Project Manager']))->get('/home')
            ->assertInertia(fn (Assert $page) => $page->component('Home'));

        // The rest of the app is not held back — only the dashboard's place.
        $this->actingAs($this->owner)->get('/clients')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Clients'));

        // Finished, the dashboard proper comes back.
        $this->company->forceFill(['onboarding_finished_at' => now()])->save();
        $this->actingAs($this->owner)->get('/home')->assertInertia(fn (Assert $page) => $page->component('Home'));
    }

    public function test_finishing_returns_the_dashboard_and_so_does_completing_every_step(): void
    {
        $this->actingAs($this->owner)->post(route('get-started.skip', 'team'));
        $this->actingAs($this->owner)->post(route('get-started.skip', 'commodities'));
        $client = $this->owner->clients()->create(['name' => 'Acme']);
        Project::create(['user_id' => $this->owner->id, 'client_id' => $client->id, 'name' => 'P', 'client' => 'Acme', 'status' => 'draft']);

        // Every required step is done, but two were skipped: the checklist stays until it is finished.
        $this->actingAs($this->owner)->get('/home')->assertInertia(fn (Assert $page) => $page->component('GetStarted')->where('checklist.canFinish', true));
        $this->actingAs($this->owner)->post(route('get-started.finish'))->assertRedirect(route('home'));
        $this->actingAs($this->owner)->get('/home')->assertInertia(fn (Assert $page) => $page->component('Home'));

        // A company that has done all five needs no finishing.
        $this->company->forceFill(['onboarding_finished_at' => null, 'onboarding_skipped' => null])->save();
        (new Foreman(['name' => 'Dana Wu', 'initials' => 'DW']))->forceFill(['company_id' => $this->company->id])->save();
        $this->price($this->owner->id, 'own');
        $this->actingAs($this->owner)->get('/home')->assertInertia(fn (Assert $page) => $page->component('Home'));
    }

    public function test_it_is_for_the_companys_managers_and_shows_only_their_companys_progress(): void
    {
        foreach (['Journeyman', 'Apprentice', 'Foreman'] as $role) {
            $user = User::factory()->create(['role' => $role, 'company_id' => $this->company->id]);
            $this->actingAs($user)->get(route('get-started.show'))->assertForbidden();
            $this->actingAs($user)->post(route('get-started.skip', 'team'))->assertForbidden();
            $this->actingAs($user)->post(route('get-started.finish'))->assertForbidden();
        }
        // An account with no company has nothing to set up.
        $this->actingAs(User::factory()->create(['role' => 'Project Manager']))->get(route('get-started.show'))->assertForbidden();

        // Another company's work does not tick anything here.
        $rival = User::factory()->create(['role' => 'Project Manager']);
        $rivalCompany = CompanyProfile::create([
            'user_id' => $rival->id, 'name' => 'Rival', 'business_address' => '1', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'r@x.test', 'timezone' => 'America/Chicago',
        ]);
        $rival->forceFill(['company_id' => $rivalCompany->id])->save();
        $rivalClient = $rival->fresh()->clients()->create(['name' => 'Rival client']);
        Project::create(['user_id' => $rival->id, 'client_id' => $rivalClient->id, 'name' => 'RP', 'client' => 'R', 'status' => 'draft']);

        $this->assertSame(['pending', 'locked'], [$this->statuses()['client'], $this->statuses()['project']]);
        $this->assertSame(0, Client::whereIn('user_id', [$this->owner->id])->count());
    }

    public function test_need_help_shows_only_the_links_that_are_set(): void
    {
        $this->open()->assertInertia(fn (Assert $page) => $page->where('help', []));

        config(['onboarding.help_links' => ['documentation' => 'https://docs.example.test', 'setup_guide' => null, 'support' => 'mailto:help@example.test']]);
        $this->open()->assertInertia(fn (Assert $page) => $page
            ->where('help.documentation', 'https://docs.example.test')
            ->where('help.support', 'mailto:help@example.test')
            ->missing('help.setup_guide'));
    }
}
