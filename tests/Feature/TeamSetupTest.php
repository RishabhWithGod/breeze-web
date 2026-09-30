<?php

namespace Tests\Feature;

use App\Models\CompanyProfile;
use App\Models\Foreman;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\TeamInvitationSent;
use App\Notifications\TeamMemberAdded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Team Setup: invite the crew and put them on teams, while the company is getting started.
 */
class TeamSetupTest extends TestCase
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

    private function team(string $name): Team
    {
        $this->actingAs($this->owner)->post(route('teams.store'), ['name' => $name, 'inline' => true]);

        return Team::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', $name)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function row(array $overrides = []): array
    {
        return ['name' => 'Sarah Miller', 'email' => 'sarah.miller@mailinator.com', 'phone' => '(410) 555-2187', 'role' => 'Foreman', 'team_id' => null, ...$overrides];
    }

    /** Sends invitations and hands back each one's link token, by email. @return array<string, string> */
    private function send(array $rows, ?User $as = null): array
    {
        Notification::fake();
        $response = $this->actingAs($as ?? $this->owner)->post(route('team-setup.invitations.store'), ['invitations' => $rows]);
        $response->assertSessionHasNoErrors();

        $tokens = [];
        Notification::assertSentOnDemand(TeamInvitationSent::class, function (TeamInvitationSent $notification) use (&$tokens) {
            $tokens[$notification->invitation->email] = $notification->token;

            return true;
        });

        return $tokens;
    }

    public function test_the_screen_shows_the_teams_the_invitations_and_the_seats(): void
    {
        $north = $this->team('North Crew');
        $this->send([$this->row(['team_id' => $north->id])]);

        $this->actingAs($this->owner)->get(route('team-setup.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('TeamSetup')
                ->has('teams', 1)
                ->where('teams.0.name', 'North Crew')
                ->where('teams.0.members', 0)
                ->has('invitations', 1)
                ->where('invitations.0.name', 'Sarah Miller')
                ->where('invitations.0.role', 'Foreman')
                ->where('invitations.0.teamName', 'North Crew')
                ->where('invitations.0.status', 'pending')
                ->where('roles', ['Project Manager', 'Foreman', 'Journeyman', 'Apprentice'])
                // Growth: ten seats, the owner has one, one invitation is out.
                ->where('seats', ['used' => 1, 'pending' => 1, 'limit' => 10, 'available' => 8]));
    }

    public function test_sending_invites_each_person_by_email_and_opens_a_week_long_link(): void
    {
        $north = $this->team('North Crew');

        $tokens = $this->send([
            $this->row(['team_id' => $north->id]),
            $this->row(['name' => 'Carlos Ramirez', 'email' => 'Carlos.Ramirez@Mailinator.com', 'phone' => '3333225323', 'role' => 'Journeyman']),
        ]);

        $this->assertCount(2, $tokens);
        $invitation = TeamInvitation::withoutGlobalScopes()->where('email', 'carlos.ramirez@mailinator.com')->sole();
        $this->assertSame('pending', $invitation->status);
        $this->assertSame($this->company->id, $invitation->company_id);
        $this->assertSame($this->owner->id, $invitation->invited_by);
        $this->assertSame('(333) 322-5323', $invitation->phone);
        $this->assertNotNull($invitation->sent_at);
        $this->assertTrue($invitation->expires_at->isSameDay(now()->addDays(7)));
        // Only a hash of the link is kept — never the link itself.
        $this->assertSame(hash('sha256', $tokens['carlos.ramirez@mailinator.com']), $invitation->token_hash);
        $this->assertStringNotContainsString($tokens['carlos.ramirez@mailinator.com'], json_encode($invitation->getAttributes()));
    }

    public function test_a_row_with_a_password_becomes_an_account_that_can_sign_in_at_once(): void
    {
        $team = $this->team('North');
        Notification::fake();

        $this->actingAs($this->owner)->post(route('team-setup.invitations.store'), ['invitations' => [
            $this->row(['password' => 'Str0ng-pass!9', 'team_id' => $team->id]),
            $this->row(['name' => 'Tom Lee', 'email' => 'tom@mailinator.com']),
        ]])->assertSessionHasNoErrors();

        $sarah = User::where('email', 'sarah.miller@mailinator.com')->firstOrFail();
        $this->assertSame($this->company->id, $sarah->company_id);
        $this->assertTrue(Hash::check('Str0ng-pass!9', $sarah->password));
        $this->assertSame(TeamInvitation::STATUS_ACCEPTED, TeamInvitation::where('email', 'sarah.miller@mailinator.com')->value('status'));
        Notification::assertSentOnDemand(TeamMemberAdded::class);

        // The one without a password is still only invited.
        $this->assertNull(User::where('email', 'tom@mailinator.com')->first());
        Notification::assertSentOnDemand(TeamInvitationSent::class);

        auth()->logout();
        $this->post('/login', ['email' => 'sarah.miller@mailinator.com', 'password' => 'Str0ng-pass!9'])->assertSessionHasNoErrors();
    }

    public function test_a_weak_password_is_refused(): void
    {
        $this->actingAs($this->owner)->post(route('team-setup.invitations.store'), ['invitations' => [$this->row(['password' => 'abc'])]])
            ->assertSessionHasErrors('invitations.0.password');
        $this->assertNull(User::where('email', 'sarah.miller@mailinator.com')->first());
    }

    public function test_the_rows_are_checked_before_anything_is_sent(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'taken@mailinator.com']);
        $this->send([$this->row(['email' => 'open@mailinator.com'])]);
        Notification::fake();
        $post = fn (array $rows) => $this->actingAs($this->owner)->post(route('team-setup.invitations.store'), ['invitations' => $rows]);

        $post([])->assertSessionHasErrors('invitations');
        $post([$this->row(['name' => ''])])->assertSessionHasErrors('invitations.0.name');
        $post([$this->row(['email' => 'not-an-email'])])->assertSessionHasErrors('invitations.0.email');
        $post([$this->row(['phone' => '12'])])->assertSessionHasErrors('invitations.0.phone');
        $post([$this->row(['role' => 'Owner'])])->assertSessionHasErrors('invitations.0.role');
        $post([$this->row(['team_id' => 9999])])->assertSessionHasErrors('invitations.0.team_id');
        // The same email twice on the list, one that already has an account, one already invited.
        $post([$this->row(), $this->row(['name' => 'Twin'])])->assertSessionHasErrors('invitations.1.email');
        $post([$this->row(['email' => 'taken@mailinator.com'])])->assertSessionHasErrors('invitations.0.email');
        $post([$this->row(['email' => 'OPEN@mailinator.com'])])->assertSessionHasErrors('invitations.0.email');

        // One bad row sends nothing.
        $post([$this->row(['email' => 'fine@mailinator.com']), $this->row(['name' => 'Bad', 'email' => 'x'])])->assertSessionHasErrors('invitations.1.email');
        Notification::assertNothingSent();
        $this->assertSame(1, TeamInvitation::withoutGlobalScopes()->count());
    }

    public function test_a_team_from_another_company_cannot_be_used(): void
    {
        $rivalOwner = User::factory()->create(['role' => 'Project Manager']);
        $rival = CompanyProfile::create([
            'user_id' => $rivalOwner->id, 'name' => 'Rival', 'business_address' => '1', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'r@x.test', 'timezone' => 'America/Chicago',
        ]);
        $rivalOwner->forceFill(['company_id' => $rival->id])->save();
        $this->actingAs($rivalOwner->fresh())->post(route('teams.store'), ['name' => 'Rival Crew', 'inline' => true]);
        $theirs = Team::withoutGlobalScopes()->where('company_id', $rival->id)->sole();

        $this->actingAs($this->owner)->post(route('team-setup.invitations.store'), ['invitations' => [$this->row(['team_id' => $theirs->id])]])
            ->assertSessionHasErrors('invitations.0.team_id');
    }

    public function test_the_plans_seats_are_enforced_and_every_open_invitation_holds_one(): void
    {
        // Starter holds two people: the owner and one more.
        Subscription::create(['user_id' => $this->owner->id, 'plan' => 'starter', 'status' => 'active', 'billing_cycle' => 'monthly', 'renews_on' => now()->addMonth()]);
        Notification::fake();
        $post = fn (array $rows) => $this->actingAs($this->owner)->post(route('team-setup.invitations.store'), ['invitations' => $rows]);

        $post([$this->row(), $this->row(['name' => 'Bea Cole', 'email' => 'bea@mailinator.com'])])
            ->assertSessionHasErrors(['invitations' => 'Your plan has 1 seat left, and you are inviting 2. Remove 1 or upgrade your plan.']);
        Notification::assertNothingSent();

        $post([$this->row()])->assertSessionHasNoErrors();
        $invitation = TeamInvitation::withoutGlobalScopes()->sole();

        // That invitation holds the last seat, so nobody else can be invited...
        $post([$this->row(['name' => 'Bea Cole', 'email' => 'bea@mailinator.com'])])
            ->assertSessionHasErrors(['invitations' => 'Your plan has no seats left. Upgrade your plan to invite more people.']);

        // ...until it is cancelled and the seat is free again.
        $this->actingAs($this->owner)->delete(route('team-setup.invitations.cancel', $invitation))->assertSessionHas('warning');
        $post([$this->row(['name' => 'Bea Cole', 'email' => 'bea@mailinator.com'])])->assertSessionHasNoErrors();
    }

    public function test_an_invitation_can_be_sent_again_on_a_new_link_or_cancelled(): void
    {
        $tokens = $this->send([$this->row()]);
        $invitation = TeamInvitation::withoutGlobalScopes()->sole();

        Notification::fake();
        $this->actingAs($this->owner)->post(route('team-setup.invitations.resend', $invitation))->assertSessionHas('success');
        $newToken = null;
        Notification::assertSentOnDemand(TeamInvitationSent::class, function (TeamInvitationSent $n) use (&$newToken) {
            $newToken = $n->token;

            return true;
        });

        // The old link stops working; the new one does.
        $this->assertNotSame($tokens['sarah.miller@mailinator.com'], $newToken);
        $this->get(route('invitations.show', $tokens['sarah.miller@mailinator.com']))->assertInertia(fn (Assert $page) => $page->where('state', 'invalid'));
        $this->get(route('invitations.show', $newToken))->assertInertia(fn (Assert $page) => $page->where('state', 'ready'));

        // Cancelled, the link is dead.
        $this->actingAs($this->owner)->delete(route('team-setup.invitations.cancel', $invitation))->assertSessionHas('warning');
        $this->assertSame('cancelled', $invitation->fresh()->status);
        $this->get(route('invitations.show', $newToken))->assertInertia(fn (Assert $page) => $page->where('state', 'cancelled'));

        // A cancelled or accepted one cannot be resent or cancelled again.
        $this->actingAs($this->owner)->post(route('team-setup.invitations.resend', $invitation))->assertStatus(409);
        $this->actingAs($this->owner)->delete(route('team-setup.invitations.cancel', $invitation))->assertStatus(409);
    }

    public function test_accepting_makes_the_account_with_the_role_and_team_and_signs_them_in(): void
    {
        $north = $this->team('North Crew');
        $tokens = $this->send([$this->row(['team_id' => $north->id])]);
        $token = $tokens['sarah.miller@mailinator.com'];

        $this->post('/logout');
        $this->get(route('invitations.show', $token))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AcceptInvitation')
                ->where('state', 'ready')
                ->where('invitation.company', 'Volt & Co')
                ->where('invitation.inviter', $this->owner->name)
                ->where('invitation.name', 'Sarah Miller')
                ->where('invitation.email', 'sarah.miller@mailinator.com')
                ->where('invitation.role', 'Foreman')
                ->where('invitation.team', 'North Crew'));

        // A weak or unconfirmed password is refused.
        $this->post(route('invitations.accept', $token), ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post(route('invitations.accept', $token), ['password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->assertSame(0, User::where('email', 'sarah.miller@mailinator.com')->count());

        $this->post(route('invitations.accept', $token), ['password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x'])
            ->assertRedirect(route('home'));

        $user = User::where('email', 'sarah.miller@mailinator.com')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Foreman', $user->role);
        $this->assertSame($this->company->id, $user->company_id);
        $this->assertSame('(410) 555-2187', $user->phone);
        $this->assertSame('active', $user->status);
        $this->assertFalse($user->needs_company_setup || $user->needs_terms_acceptance || $user->needs_payment_setup);

        // On the crew register, on the team they were invited to.
        $foreman = Foreman::withoutGlobalScopes()->where('user_id', $user->id)->sole();
        $this->assertSame('foreman', $foreman->role);
        $this->assertSame($north->id, $foreman->team_id);
        $this->assertSame($this->company->id, $foreman->company_id);
        $this->assertSame($north->id, TeamMember::withoutGlobalScopes()->where('user_id', $user->id)->sole()->team_id);

        $invitation = TeamInvitation::withoutGlobalScopes()->sole();
        $this->assertSame('accepted', $invitation->status);
        $this->assertNotNull($invitation->accepted_at);
        $this->assertSame($user->id, $invitation->user_id);

        // The link is used up.
        $this->post('/logout');
        $this->get(route('invitations.show', $token))->assertInertia(fn (Assert $page) => $page->where('state', 'accepted'));
        $this->post(route('invitations.accept', $token), ['password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x'])->assertNotFound();
    }

    public function test_a_project_manager_invited_by_the_owner_joins_as_a_manager_not_crew(): void
    {
        $tokens = $this->send([$this->row(['name' => 'Riley Park', 'email' => 'riley@mailinator.com', 'role' => 'Project Manager'])]);

        $this->post('/logout');
        $this->post(route('invitations.accept', $tokens['riley@mailinator.com']), ['password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x'])
            ->assertRedirect(route('home'));

        $riley = User::where('email', 'riley@mailinator.com')->sole();
        $this->assertSame('Project Manager', $riley->role);
        $this->assertSame($this->company->id, $riley->company_id);
        $this->assertSame(0, Foreman::withoutGlobalScopes()->where('user_id', $riley->id)->count());
    }

    public function test_only_the_owner_can_invite_a_project_manager(): void
    {
        $colleague = User::factory()->create(['role' => 'Project Manager', 'company_id' => $this->company->id]);

        $this->actingAs($colleague)->get(route('team-setup.show'))
            ->assertInertia(fn (Assert $page) => $page->where('roles', ['Foreman', 'Journeyman', 'Apprentice']));

        $this->actingAs($colleague)->post(route('team-setup.invitations.store'), ['invitations' => [$this->row(['role' => 'Project Manager'])]])
            ->assertSessionHasErrors('invitations.0.role');
        $this->actingAs($colleague)->post(route('team-setup.invitations.store'), ['invitations' => [$this->row()]])->assertSessionHasNoErrors();
    }

    public function test_an_invitation_that_lapsed_or_whose_email_was_taken_cannot_be_accepted(): void
    {
        $tokens = $this->send([$this->row(), $this->row(['name' => 'Emma Clark', 'email' => 'emma.clark@mailinator.com'])]);
        $password = ['password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x'];
        $this->post('/logout');

        // Past its week: shown as expired, refused, and it can be sent again.
        $this->travel(8)->days();
        $this->get(route('invitations.show', $tokens['sarah.miller@mailinator.com']))->assertInertia(fn (Assert $page) => $page->where('state', 'expired'));
        $this->post(route('invitations.accept', $tokens['sarah.miller@mailinator.com']), $password)->assertNotFound();
        $this->travelBack();

        // Someone registers with that email first: the acceptance is refused, not merged.
        User::factory()->create(['email' => 'emma.clark@mailinator.com']);
        $this->post(route('invitations.accept', $tokens['emma.clark@mailinator.com']), $password)->assertSessionHasErrors('email');
        $this->assertSame('pending', TeamInvitation::withoutGlobalScopes()->where('email', 'emma.clark@mailinator.com')->sole()->status);
    }

    public function test_a_plan_that_filled_up_meanwhile_stops_the_last_acceptance(): void
    {
        Subscription::create(['user_id' => $this->owner->id, 'plan' => 'starter', 'status' => 'active', 'billing_cycle' => 'monthly', 'renews_on' => now()->addMonth()]);
        $tokens = $this->send([$this->row()]);
        $this->post('/logout');

        // Another manager adds someone directly, taking the seat the invitation was holding.
        User::factory()->create(['company_id' => $this->company->id, 'status' => 'active']);

        $this->post(route('invitations.accept', $tokens['sarah.miller@mailinator.com']), ['password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x'])
            ->assertSessionHasErrors('invitation');
        $this->assertSame(0, User::where('email', 'sarah.miller@mailinator.com')->count());
    }

    public function test_it_is_for_the_companys_managers_and_only_while_getting_started(): void
    {
        foreach (['Journeyman', 'Apprentice', 'Foreman', 'Estimator'] as $role) {
            $user = User::factory()->create(['role' => $role, 'company_id' => $this->company->id]);
            $this->actingAs($user)->get(route('team-setup.show'))->assertForbidden();
            $this->actingAs($user)->post(route('team-setup.invitations.store'), ['invitations' => [$this->row()]])->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'Project Manager']))->get(route('team-setup.show'))->assertForbidden();

        // Another company cannot see or touch these invitations.
        $this->send([$this->row()]);
        $invitation = TeamInvitation::withoutGlobalScopes()->sole();
        $rivalOwner = User::factory()->create(['role' => 'Project Manager']);
        $rival = CompanyProfile::create([
            'user_id' => $rivalOwner->id, 'name' => 'Rival', 'business_address' => '1', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'r@x.test', 'timezone' => 'America/Chicago',
        ]);
        $rivalOwner->forceFill(['company_id' => $rival->id])->save();
        $rivalOwner = $rivalOwner->fresh();
        $this->actingAs($rivalOwner)->get(route('team-setup.show'))->assertInertia(fn (Assert $page) => $page->has('invitations', 0));
        $this->actingAs($rivalOwner)->post(route('team-setup.invitations.resend', $invitation))->assertNotFound();
        $this->actingAs($rivalOwner)->delete(route('team-setup.invitations.cancel', $invitation))->assertNotFound();

        // Once setup is finished the Teams register is where the crew is managed.
        $this->company->forceFill(['onboarding_finished_at' => now()])->save();
        $this->actingAs($this->owner)->get(route('team-setup.show'))->assertRedirect(route('teams.index'));
    }

    public function test_an_invitation_out_starts_the_team_on_the_checklist_and_it_points_here(): void
    {
        $this->actingAs($this->owner)->get(route('get-started.show'))
            ->assertInertia(fn (Assert $page) => $page->where('checklist.steps.1.status', 'pending')->where('checklist.steps.1.href', '/team-setup'));

        $this->send([$this->row()]);

        $this->actingAs($this->owner)->get(route('get-started.show'))
            ->assertInertia(fn (Assert $page) => $page->where('checklist.steps.1.status', 'completed'));
    }
}
