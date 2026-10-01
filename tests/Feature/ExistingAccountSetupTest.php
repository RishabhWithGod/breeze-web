<?php

namespace Tests\Feature;

use App\Models\CompanyProfile;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A manager from before companies existed is sent through setup when they come back.
 */
class ExistingAccountSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['company.require_setup_for_existing' => true]);
    }

    public function test_a_manager_with_no_company_is_sent_to_setup_on_their_next_visit(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);

        $this->actingAs($manager)->get(route('home'))->assertRedirect(route('company.setup.create'));

        $manager->refresh();
        $this->assertTrue($manager->needs_company_setup);
        $this->assertTrue($manager->needs_terms_acceptance);
        $this->assertTrue($manager->needs_payment_setup);

        // ...and stays there until it is done, whatever they open.
        $this->get('/clients')->assertRedirect(route('company.setup.create'));
        $this->get(route('company.setup.create'))->assertOk();
    }

    public function test_logging_in_leads_there(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager', 'email' => 'old@example.com']);

        $this->post('/login', ['email' => 'old@example.com', 'password' => 'password'])->assertRedirect();
        $this->get(route('home'))->assertRedirect(route('company.setup.create'));
    }

    public function test_crew_and_mobile_accounts_are_not_made_to_set_a_company_up(): void
    {
        foreach (['Foreman', 'Journeyman', 'Apprentice'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get(route('home'))->assertOk();
        }

        $mobile = User::factory()->create(['role' => 'Project Manager']);
        $mobile->forceFill(['registration_source' => User::SOURCE_MOBILE])->save();
        $this->actingAs($mobile)->get(route('home'))->assertOk();
    }

    public function test_an_account_that_already_has_a_company_is_left_alone(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $company = CompanyProfile::create([
            'user_id' => $manager->id, 'name' => 'Volt', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        $manager->forceFill(['company_id' => $company->id])->save();

        $this->actingAs($manager->fresh())->get(route('home'))->assertOk();
    }

    public function test_a_company_described_but_never_linked_is_linked_not_asked_again(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $company = CompanyProfile::create([
            'user_id' => $manager->id, 'name' => 'Volt', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);

        $this->actingAs($manager)->get(route('home'))->assertOk();
        $this->assertSame($company->id, $manager->fresh()->company_id);
        $this->assertFalse($manager->fresh()->needs_company_setup);
    }

    public function test_it_can_be_switched_off(): void
    {
        config(['company.require_setup_for_existing' => false]);

        $this->actingAs(User::factory()->create(['role' => 'Project Manager']))->get(route('home'))->assertOk();
    }

    public function test_the_crew_from_before_companies_can_be_handed_to_one(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager', 'email' => 'boss@example.com']);
        $company = CompanyProfile::create([
            'user_id' => $manager->id, 'name' => 'Volt', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        $manager->forceFill(['company_id' => $company->id])->save();

        Team::create(['name' => 'Old Crew']);
        Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);
        $crewLogin = User::factory()->create(['role' => 'Journeyman']);
        $otherManager = User::factory()->create(['role' => 'Project Manager']);

        $this->artisan('company:adopt-legacy', ['email' => 'boss@example.com'])->assertSuccessful();

        $this->assertSame($company->id, Team::withoutGlobalScopes()->sole()->company_id);
        $this->assertSame($company->id, Foreman::withoutGlobalScopes()->sole()->company_id);
        $this->assertSame($company->id, $crewLogin->fresh()->company_id);
        // Another manager with no company is not this company's crew.
        $this->assertNull($otherManager->fresh()->company_id);

        // An account with no company yet cannot adopt anything.
        $this->artisan('company:adopt-legacy', ['email' => $otherManager->email])->assertFailed();
    }

    public function test_other_managers_and_their_work_can_come_with_it(): void
    {
        $boss = User::factory()->create(['role' => 'Project Manager', 'email' => 'boss@example.com']);
        $company = CompanyProfile::create([
            'user_id' => $boss->id, 'name' => 'Volt', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        $boss->forceFill(['company_id' => $company->id])->save();

        $other = User::factory()->create(['role' => 'Project Manager']);
        $job = Job::create(['user_id' => $other->id, 'name' => 'Old Job', 'client' => 'Acme', 'status' => 'planning']);

        // Not theirs yet...
        $this->actingAs($boss->fresh())->get(route('jobs.show', $job))->assertForbidden();

        $this->artisan('company:adopt-legacy', ['email' => 'boss@example.com', '--managers' => true])->assertSuccessful();

        // ...and once adopted, the company's managers see all of it.
        $this->assertSame($company->id, $other->fresh()->company_id);
        $this->actingAs($boss->fresh())->get(route('jobs.show', $job))->assertOk();
    }

    public function test_work_with_no_owner_is_taken_on_and_an_estimate_gets_the_next_number(): void
    {
        $boss = User::factory()->create(['role' => 'Project Manager', 'email' => 'boss@example.com']);
        $company = CompanyProfile::create([
            'user_id' => $boss->id, 'name' => 'Volt', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        $boss->forceFill(['company_id' => $company->id])->save();

        $make = fn (?int $owner) => DB::table('estimates')->insertGetId([
            'user_id' => $owner, 'number' => 'EST-1001', 'client' => 'Acme', 'project' => 'P', 'issued_on' => '2026-01-01', 'amount' => 0, 'kind' => 'standalone', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $mine = $make($boss->id);
        $orphan = $make(null);   // shares the number EST-1001 with the boss's own estimate
        $job = DB::table('work_jobs')->insertGetId([
            'user_id' => null, 'name' => 'Ownerless', 'client' => 'Acme', 'status' => 'planning', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('company:adopt-legacy', ['email' => 'boss@example.com'])->assertSuccessful();

        $this->assertSame($boss->id, DB::table('estimates')->where('id', $orphan)->value('user_id'));
        $this->assertSame('EST-1002', DB::table('estimates')->where('id', $orphan)->value('number'));
        $this->assertSame('EST-1001', DB::table('estimates')->where('id', $mine)->value('number'));
        $this->assertSame($boss->id, DB::table('work_jobs')->where('id', $job)->value('user_id'));
    }
}
