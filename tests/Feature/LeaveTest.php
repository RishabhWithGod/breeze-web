<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\CompanyProfile;
use App\Models\Foreman;
use App\Models\LeaveRequest;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\Leave\LeaveAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Leave from the field app to the manager's decision, and the team member's account switched
 * off and on. The count of days until someone is back is worked out from today, so these tests
 * move the clock rather than waiting for it.
 */
class LeaveTest extends TestCase
{
    use RefreshDatabase;

    private CompanyProfile $company;

    private User $manager;

    private User $tech;

    private Foreman $member;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-02 09:00:00');

        $this->manager = User::factory()->create(['role' => 'Project Manager']);
        $this->company = CompanyProfile::create([
            'user_id' => $this->manager->id, 'name' => 'Volt & Co', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        $this->company->forceFill(['onboarding_finished_at' => now()])->save();
        $this->manager->forceFill(['company_id' => $this->company->id])->save();

        [$this->tech, $this->member] = $this->technician('Sam Rivera');
    }

    /** @return array{0: User, 1: Foreman} */
    private function technician(string $name, string $role = 'Journeyman'): array
    {
        $user = User::factory()->create(['name' => $name, 'role' => $role, 'company_id' => $this->company->id]);
        $user->forceFill(['status' => User::STATUS_ACTIVE, 'registration_source' => User::SOURCE_MOBILE])->save();
        // Written the way the app writes them — company stamped, not mass-assigned.
        $team = Team::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'Crew A')->first();
        if ($team === null) {
            $team = new Team(['name' => 'Crew A']);
            $team->company_id = $this->company->id;
            $team->save();
        }
        $member = $this->roster(['name' => $name, 'initials' => 'SR', 'team_id' => $team->id, 'role' => strtolower($role), 'user_id' => $user->id]);

        return [$user->fresh(), $member];
    }

    /** @param array<string, mixed> $attributes */
    private function roster(array $attributes): Foreman
    {
        $member = new Foreman($attributes);
        $member->company_id = $this->company->id;
        $member->user_id = $attributes['user_id'] ?? null;
        $member->save();

        return $member;
    }

    private function apply(User $user, string $from, string $to, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($user->createToken('t')->plainTextToken)
            ->postJson('/api/v1/leave', array_merge(['type' => 'vacation', 'start_date' => $from, 'end_date' => $to], $extra));
    }

    private function grant(User $tech, string $from, string $to): LeaveRequest
    {
        return LeaveRequest::create([
            'company_id' => $this->company->id, 'user_id' => $tech->id, 'type' => 'vacation',
            'start_date' => $from, 'end_date' => $to, 'status' => LeaveRequest::STATUS_APPROVED,
        ]);
    }

    private function availability(User $user): array
    {
        return app(LeaveAvailability::class)->statusFor($user->id, $this->company->id);
    }

    public function test_the_phone_asks_for_leave_and_managers_are_told(): void
    {
        $this->apply($this->tech, '2026-11-04', '2026-11-06', ['reason' => 'Family trip'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.days', 3)
            ->assertJsonPath('data.canCancel', true);

        $leave = LeaveRequest::sole();
        $this->assertSame($this->company->id, $leave->company_id);
        $this->assertSame('Family trip', $leave->reason);

        // The manager is told; the person who asked is not.
        $this->assertSame(1, AppNotification::where('user_id', $this->manager->id)->where('type', 'leave-requested')->count());
        $this->assertSame(0, AppNotification::where('user_id', $this->tech->id)->count());
    }

    public function test_bad_requests_are_refused_with_a_reason(): void
    {
        $this->apply($this->tech, '2026-11-01', '2026-11-03')->assertStatus(422)->assertJsonValidationErrors('start_date');
        $this->apply($this->tech, '2026-11-06', '2026-11-04')->assertStatus(422)->assertJsonValidationErrors('end_date');
        $this->apply($this->tech, '2026-11-04', '2027-03-01')->assertStatus(422)->assertJsonValidationErrors('end_date');
        $this->apply($this->tech, '2026-11-04', '2026-11-06', ['type' => 'nonsense'])->assertStatus(422)->assertJsonValidationErrors('type');

        // Today is allowed.
        $this->apply($this->tech, '2026-11-02', '2026-11-02')->assertCreated();
        // Overlapping what is already asked for is not.
        $this->apply($this->tech, '2026-11-02', '2026-11-05')->assertStatus(422)->assertJsonValidationErrors('start_date');
        $this->assertSame(1, LeaveRequest::count());
    }

    public function test_a_manager_approves_and_the_person_is_told(): void
    {
        $this->apply($this->tech, '2026-11-04', '2026-11-06');
        $leave = LeaveRequest::sole();

        $this->actingAs($this->manager)->post(route('leave.approve', $leave), ['note' => 'Enjoy'])->assertRedirect();

        $leave->refresh();
        $this->assertSame('approved', $leave->status);
        $this->assertSame($this->manager->id, $leave->decided_by);
        $this->assertSame('Enjoy', $leave->decision_note);
        $this->assertSame(1, AppNotification::where('user_id', $this->tech->id)->where('type', 'leave-approved')->count());

        // Deciding twice is refused: it has been decided.
        $this->actingAs($this->manager)->post(route('leave.reject', $leave), ['note' => 'No'])->assertSessionHasErrors('leave');
        $this->assertSame('approved', $leave->fresh()->status);
    }

    public function test_rejecting_needs_a_reason(): void
    {
        $this->apply($this->tech, '2026-11-04', '2026-11-06');
        $leave = LeaveRequest::sole();

        $this->actingAs($this->manager)->post(route('leave.reject', $leave), [])->assertSessionHasErrors('note');
        $this->assertSame('pending', $leave->fresh()->status);

        $this->actingAs($this->manager)->post(route('leave.reject', $leave), ['note' => 'Inspection week'])->assertRedirect();
        $this->assertSame('rejected', $leave->fresh()->status);
        $this->assertSame(1, AppNotification::where('user_id', $this->tech->id)->where('type', 'leave-rejected')->count());
    }

    public function test_only_managers_of_the_company_decide(): void
    {
        $this->apply($this->tech, '2026-11-04', '2026-11-06');
        $leave = LeaveRequest::sole();

        // A crew member cannot decide, nor open the page.
        $this->actingAs($this->tech)->post(route('leave.approve', $leave))->assertForbidden();
        $this->actingAs($this->tech)->get(route('leave.index'))->assertForbidden();

        // Another company's manager does not even see it.
        $otherManager = User::factory()->create(['role' => 'Project Manager']);
        $other = CompanyProfile::create([
            'user_id' => $otherManager->id, 'name' => 'Other Co', 'business_address' => '2 Main St', 'primary_contact' => 'B',
            'phone' => '(512) 555-0143', 'email' => 'p@x.test', 'timezone' => 'America/Chicago',
        ]);
        $other->forceFill(['onboarding_finished_at' => now()])->save();
        $otherManager->forceFill(['company_id' => $other->id])->save();

        $this->actingAs($otherManager)->post(route('leave.approve', $leave))->assertNotFound();
        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_the_count_of_days_left_drops_every_day_and_then_they_are_available(): void
    {
        $this->grant($this->tech, '2026-11-03', '2026-11-07');

        // The day before: not yet away, starts in 1 day.
        $status = $this->availability($this->tech);
        $this->assertSame('upcoming', $status['state']);
        $this->assertSame(1, $status['startsIn']);

        $expected = ['2026-11-03' => 5, '2026-11-04' => 4, '2026-11-05' => 3, '2026-11-06' => 2, '2026-11-07' => 1];
        foreach ($expected as $day => $left) {
            Carbon::setTestNow("{$day} 08:00:00");
            $status = $this->availability($this->tech);
            $this->assertSame('on_leave', $status['state'], $day);
            $this->assertSame($left, $status['daysLeft'], $day);
            $this->assertSame('2026-11-08', $status['availableOn'], $day);
        }

        // The morning after the last day: available, nothing counted.
        Carbon::setTestNow('2026-11-08 08:00:00');
        $status = $this->availability($this->tech);
        $this->assertSame('available', $status['state']);
        $this->assertNull($status['daysLeft']);
    }

    public function test_back_to_back_leave_counts_as_one_absence(): void
    {
        $this->grant($this->tech, '2026-11-03', '2026-11-05');
        $this->grant($this->tech, '2026-11-06', '2026-11-09');

        Carbon::setTestNow('2026-11-03 08:00:00');
        $status = $this->availability($this->tech);

        $this->assertSame(7, $status['daysLeft']);
        $this->assertSame('2026-11-10', $status['availableOn']);
    }

    public function test_today_is_the_companys_today_not_the_servers(): void
    {
        // 02:00 UTC on Nov 4 is still the evening of Nov 3 in Chicago.
        Carbon::setTestNow('2026-11-04 02:00:00');
        $this->grant($this->tech, '2026-11-03', '2026-11-03');

        $status = $this->availability($this->tech);
        $this->assertSame('on_leave', $status['state']);
        $this->assertSame(1, $status['daysLeft']);
    }

    public function test_waiting_leave_is_shown_as_waiting_and_does_not_make_anyone_unavailable(): void
    {
        $this->apply($this->tech, '2026-11-04', '2026-11-06');

        $status = $this->availability($this->tech);
        $this->assertSame('pending', $status['state']);
        $this->assertSame(1, $status['pending']);
    }

    public function test_teams_shows_who_is_on_leave_and_for_how_long(): void
    {
        $this->grant($this->tech, '2026-11-02', '2026-11-06');

        $this->actingAs($this->manager)->get(route('teams.index'))->assertInertia(fn (Assert $page) => $page
            ->where('teams.data.0.members.0.name', 'Sam Rivera')
            ->where('teams.data.0.members.0.leave.state', 'on_leave')
            ->where('teams.data.0.members.0.leave.daysLeft', 5)
            ->where('teams.data.0.members.0.leave.availableOn', '2026-11-07')
            ->where('teams.data.0.members.0.hasAccount', true)
            ->where('canManageLeave', true));

        // Tomorrow the same screen says one fewer.
        Carbon::setTestNow('2026-11-03 09:00:00');
        $this->actingAs($this->manager)->get(route('teams.index'))->assertInertia(fn (Assert $page) => $page
            ->where('teams.data.0.members.0.leave.daysLeft', 4));
    }

    public function test_the_manage_leave_page_lists_what_waits_with_its_cost(): void
    {
        $this->apply($this->tech, '2026-11-04', '2026-11-06');
        $leave = LeaveRequest::sole();

        $this->actingAs($this->manager)->get(route('leave.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Leave')
            ->has('pending', 1)
            ->where('pending.0.member.name', 'Sam Rivera')
            ->where('pending.0.days', 3)
            ->where('pending.0.shiftsAffected', 0)
            ->has('approved', 0)
            ->where("overlap.{$leave->id}", []));
    }

    public function test_a_person_cancels_their_own_leave_until_it_starts(): void
    {
        $this->apply($this->tech, '2026-11-04', '2026-11-06');
        $leave = LeaveRequest::sole();
        $token = $this->tech->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson("/api/v1/leave/{$leave->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        // Granted and under way: no longer theirs to cancel.
        $under = $this->grant($this->tech, '2026-11-02', '2026-11-05');
        $this->withToken($token)->postJson("/api/v1/leave/{$under->id}/cancel")->assertStatus(422);
        $this->assertSame('approved', $under->fresh()->status);

        // Someone else's is not theirs at all.
        [$other] = $this->technician('Pat Lee');
        $mine = $this->grant($other, '2026-11-20', '2026-11-21');
        $this->withToken($token)->postJson("/api/v1/leave/{$mine->id}/cancel")->assertNotFound();
    }

    public function test_the_app_reads_its_own_status_and_requests(): void
    {
        $this->grant($this->tech, '2026-11-02', '2026-11-04');
        [$other] = $this->technician('Pat Lee');
        $this->grant($other, '2026-11-10', '2026-11-11');

        $this->withToken($this->tech->createToken('t')->plainTextToken)->getJson('/api/v1/leave')
            ->assertOk()
            ->assertJsonPath('data.status.state', 'on_leave')
            ->assertJsonPath('data.status.daysLeft', 3)
            ->assertJsonCount(1, 'data.requests')
            ->assertJsonPath('data.requests.0.days', 3);
    }

    public function test_a_manager_can_take_back_approved_leave(): void
    {
        $leave = $this->grant($this->tech, '2026-11-04', '2026-11-06');

        $this->actingAs($this->manager)->post(route('leave.revoke', $leave), ['note' => 'Job moved'])->assertRedirect();

        $this->assertSame('cancelled', $leave->fresh()->status);
        $this->assertSame('available', $this->availability($this->tech)['state']);
        $this->assertSame(1, AppNotification::where('user_id', $this->tech->id)->where('type', 'leave-cancelled')->count());
    }

    public function test_deactivating_signs_them_out_keeps_their_history_and_frees_the_seat(): void
    {
        Subscription::create(['user_id' => $this->manager->id, 'plan' => 'starter', 'seats' => 2, 'status' => 'active', 'billing_cycle' => 'monthly', 'renews_on' => now()->addMonth()]);
        $token = $this->tech->createToken('phone')->plainTextToken;
        $upcoming = $this->grant($this->tech, '2026-11-10', '2026-11-12');

        // Two seats, two people (the manager and Sam).
        $this->actingAs($this->manager)->get(route('teams.index'))->assertInertia(fn (Assert $page) => $page
            ->where('seats.used', 2)->where('seats.limit', 2)->where('seats.available', 0));

        $this->actingAs($this->manager)->post(route('foremen.deactivate', $this->member))->assertSessionHas('success');

        $this->assertSame('inactive', $this->tech->fresh()->status);
        $this->assertSame(0, $this->tech->tokens()->count());
        // The record of them is still there.
        $this->assertNotNull(Foreman::find($this->member->id));
        $this->assertSame('cancelled', $upcoming->fresh()->status);

        // The phone's old token no longer opens anything.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/leave')->assertUnauthorized();

        // Signing in again is refused, by the right words, on the web and on the phone.
        $this->post('/login', ['email' => $this->tech->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->postJson('/api/v1/auth/login', ['email' => $this->tech->email, 'password' => 'password'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        // The seat is free again.
        $this->actingAs($this->manager)->get(route('teams.index'))->assertInertia(fn (Assert $page) => $page
            ->where('seats.used', 1)->where('seats.available', 1)
            ->where('teams.data.0.members.0.inactive', true));
    }

    public function test_switching_an_account_back_on_needs_a_free_seat(): void
    {
        Subscription::create(['user_id' => $this->manager->id, 'plan' => 'starter', 'seats' => 2, 'status' => 'active', 'billing_cycle' => 'monthly', 'renews_on' => now()->addMonth()]);
        $this->actingAs($this->manager)->post(route('foremen.deactivate', $this->member));

        // Someone else takes the freed seat.
        $this->technician('Pat Lee');

        $this->actingAs($this->manager)->post(route('foremen.reactivate', $this->member))->assertSessionHasErrors('seats');
        $this->assertSame('inactive', $this->tech->fresh()->status);

        // A seat appears: they are back.
        Subscription::query()->where('user_id', $this->manager->id)->update(['seats' => 3]);
        $this->actingAs($this->manager)->post(route('foremen.reactivate', $this->member))->assertSessionHas('success');
        $this->assertSame('active', $this->tech->fresh()->status);
    }

    public function test_deactivating_is_for_managers_and_not_for_yourself_or_the_owner(): void
    {
        $this->actingAs($this->tech)->post(route('foremen.deactivate', $this->member))->assertForbidden();

        $owner = User::factory()->create(['role' => 'Owner', 'company_id' => $this->company->id, 'name' => 'Olive Owner']);
        $team = Team::withoutGlobalScopes()->first();
        $ownerRow = $this->roster(['name' => 'Olive Owner', 'initials' => 'OO', 'team_id' => $team->id, 'role' => 'foreman', 'user_id' => $owner->id]);
        $this->actingAs($this->manager)->post(route('foremen.deactivate', $ownerRow))->assertSessionHas('warning');
        $this->assertSame('active', $owner->fresh()->status);

        $selfRow = $this->roster(['name' => 'Pm', 'initials' => 'PM', 'team_id' => $team->id, 'role' => 'foreman', 'user_id' => $this->manager->id]);
        $this->actingAs($this->manager)->post(route('foremen.deactivate', $selfRow))->assertSessionHas('warning');
        $this->assertSame('active', $this->manager->fresh()->status);
    }

    /** FCM made ready the way the push tests do: real-shaped credentials, every call faked. */
    private function fakeFcm(): void
    {
        $credentials = storage_path('framework/testing/fcm-leave.json');
        @mkdir(dirname($credentials), 0777, true);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        file_put_contents($credentials, json_encode(['client_email' => 'svc@test.iam', 'private_key' => $pem]));
        config(['services.fcm.project_id' => 'demo', 'services.fcm.credentials' => $credentials]);

        \Illuminate\Support\Facades\Http::fake([
            'oauth2.googleapis.com/*' => \Illuminate\Support\Facades\Http::response(['access_token' => 'at']),
            'fcm.googleapis.com/*' => \Illuminate\Support\Facades\Http::response(['name' => 'ok']),
        ]);
    }

    public function test_the_phone_gets_a_push_when_leave_is_approved_rejected_or_taken_back(): void
    {
        $this->fakeFcm();
        \App\Models\DeviceToken::create(['user_id' => $this->tech->id, 'token' => 'sam-phone', 'platform' => 'android']);
        // A manager's own phone is not told about the answer to someone else's leave.
        \App\Models\DeviceToken::create(['user_id' => $this->manager->id, 'token' => 'manager-phone', 'platform' => 'android']);

        $approve = $this->grantPending('2026-11-04', '2026-11-06');
        $reject = $this->grantPending('2026-11-10', '2026-11-11');
        $revoke = $this->grant($this->tech, '2026-11-20', '2026-11-21');

        $this->actingAs($this->manager)->post(route('leave.approve', $approve), ['note' => 'Enjoy'])->assertRedirect();
        $this->actingAs($this->manager)->post(route('leave.reject', $reject), ['note' => 'Inspection week'])->assertRedirect();
        $this->actingAs($this->manager)->post(route('leave.revoke', $revoke), ['note' => 'Job moved'])->assertRedirect();

        // The queue runs the push jobs here, so each answer reaches FCM for Sam's phone and only his.
        $sent = \Illuminate\Support\Facades\Http::recorded(fn ($request) => str_contains($request->url(), 'messages:send'))
            ->map(fn ($pair) => $pair[0]['message']);

        $this->assertCount(3, $sent);
        $this->assertSame(['sam-phone'], $sent->pluck('token')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(
            ['leave-approved', 'leave-rejected', 'leave-cancelled'],
            $sent->pluck('data.type')->all(),
        );
        // Tapping any of them opens the Leave screen; the words say what was decided.
        $this->assertSame(['leave'], $sent->pluck('data.target_screen')->unique()->values()->all());
        $this->assertContains('Your leave was approved', $sent->pluck('notification.title')->all());
        $this->assertContains('Your leave was not approved', $sent->pluck('notification.title')->all());

        @unlink(storage_path('framework/testing/fcm-leave.json'));
    }

    private function grantPending(string $from, string $to): LeaveRequest
    {
        return LeaveRequest::create([
            'company_id' => $this->company->id, 'user_id' => $this->tech->id, 'type' => 'vacation',
            'start_date' => $from, 'end_date' => $to, 'status' => LeaveRequest::STATUS_PENDING,
        ]);
    }
}
