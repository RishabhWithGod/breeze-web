<?php

namespace Tests\Feature;

use App\Models\CompanyProfile;
use App\Models\Foreman;
use App\Models\LeaveRequest;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The manager's side of leave, team accounts and the plan — through the app's API. */
class OrgAppManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private CompanyProfile $company;

    private User $tech;

    private Foreman $member;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-02 09:00:00');

        $this->owner = User::factory()->create(['role' => 'Owner']);
        $this->company = CompanyProfile::create([
            'user_id' => $this->owner->id, 'name' => 'Volt & Co', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        $this->company->forceFill(['onboarding_finished_at' => now()])->save();
        $this->owner->forceFill(['company_id' => $this->company->id])->save();
        $this->owner = $this->owner->fresh();

        Subscription::updateOrCreate(['user_id' => $this->owner->id], [
            'plan' => 'growth', 'seats' => 3, 'status' => 'active', 'billing_cycle' => 'monthly',
            'renews_on' => now()->addDays(15),
        ]);

        $this->tech = User::factory()->create(['name' => 'Sam Rivera', 'role' => 'Journeyman', 'company_id' => $this->company->id]);
        $this->tech->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $this->member = new Foreman(['name' => 'Sam Rivera', 'initials' => 'SR', 'role' => 'journeyman']);
        $this->member->company_id = $this->company->id;
        $this->member->user_id = $this->tech->id;
        $this->member->save();
    }

    private function as(User $user): static
    {
        // The guard remembers who asked last; a new caller must be read afresh.
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('t')->plainTextToken);
    }

    private function ask(string $from, string $to): LeaveRequest
    {
        $this->as($this->tech)->postJson('/api/v1/leave', ['type' => 'vacation', 'start_date' => $from, 'end_date' => $to])->assertCreated();

        return LeaveRequest::sole();
    }

    public function test_a_manager_sees_the_requests_and_answers_them(): void
    {
        $leave = $this->ask('2026-11-04', '2026-11-06');

        $this->as($this->owner)->getJson('/api/v1/leave-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data.pending')
            ->assertJsonPath('data.pending.0.member.name', 'Sam Rivera')
            ->assertJsonPath('data.pending.0.days', 3);

        $this->as($this->owner)->postJson("/api/v1/leave-requests/{$leave->id}/reject", [])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->as($this->owner)->postJson("/api/v1/leave-requests/{$leave->id}/approve", ['note' => 'Enjoy'])->assertOk();
        $this->assertSame('approved', $leave->fresh()->status);

        $this->as($this->owner)->getJson('/api/v1/leave-requests')->assertJsonCount(1, 'data.approved')->assertJsonPath('data.approved.0.canRevoke', true);
        $this->as($this->owner)->postJson("/api/v1/leave-requests/{$leave->id}/revoke")->assertOk();
        $this->assertSame('cancelled', $leave->fresh()->status);
    }

    public function test_the_crew_cannot_manage_leave(): void
    {
        $leave = $this->ask('2026-11-04', '2026-11-06');

        $this->as($this->tech)->getJson('/api/v1/leave-requests')->assertForbidden();
        $this->as($this->tech)->postJson("/api/v1/leave-requests/{$leave->id}/approve")->assertForbidden();
        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_the_team_register_carries_availability_and_the_detail_carries_leave_and_account(): void
    {
        $leave = $this->ask('2026-11-02', '2026-11-04');
        $this->as($this->owner)->postJson("/api/v1/leave-requests/{$leave->id}/approve")->assertOk();

        $row = $this->as($this->owner)->getJson('/api/v1/team')->assertOk()->json('data.members.0');
        $this->assertTrue($row['hasLogin']);
        $this->assertSame('active', $row['accountStatus']);
        $this->assertNotNull($row['availability']);

        $this->as($this->owner)->getJson("/api/v1/team/{$this->member->id}")
            ->assertOk()
            ->assertJsonPath('data.account.canDeactivate', true)
            ->assertJsonCount(1, 'data.leave.requests');
    }

    public function test_deactivating_frees_the_seat_and_reactivating_needs_one(): void
    {
        $this->as($this->owner)->postJson("/api/v1/team/{$this->member->id}/deactivate")->assertOk();
        $this->assertSame(User::STATUS_INACTIVE, $this->tech->fresh()->status);
        $this->as($this->owner)->postJson("/api/v1/team/{$this->member->id}/deactivate")->assertStatus(422);

        $this->as($this->owner)->postJson("/api/v1/team/{$this->member->id}/reactivate")->assertOk();
        $this->assertSame(User::STATUS_ACTIVE, $this->tech->fresh()->status);
    }

    public function test_nobody_can_deactivate_themselves_or_the_owner_and_the_crew_cannot_at_all(): void
    {
        $self = new Foreman(['name' => 'Owner', 'initials' => 'OW', 'role' => 'foreman']);
        $self->company_id = $this->company->id;
        $self->user_id = $this->owner->id;
        $self->save();

        $this->as($this->owner)->postJson("/api/v1/team/{$self->id}/deactivate")->assertStatus(422);
        $this->as($this->tech)->postJson("/api/v1/team/{$this->member->id}/deactivate")->assertForbidden();
    }

    public function test_plan_details_and_buying_seats(): void
    {
        $this->as($this->owner)->getJson('/api/v1/office/settings/plan')
            ->assertOk()
            ->assertJsonStructure(['data' => ['subscription' => ['seats', 'plans'], 'seatOffer' => ['perSeatCents', 'maxAdd'], 'blockers']]);

        $this->as($this->owner)->postJson('/api/v1/office/settings/seats', ['seats' => 0])->assertStatus(422)->assertJsonValidationErrors('seats');
        $this->as($this->owner)->postJson('/api/v1/office/settings/seats', ['seats' => 4])->assertOk();
        $this->assertSame(7, Subscription::forUser($this->owner)->seats);

        $this->as($this->tech)->getJson('/api/v1/office/settings/plan')->assertForbidden();
        $this->as($this->tech)->postJson('/api/v1/office/settings/seats', ['seats' => 1])->assertForbidden();
    }
}
