<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who may listen on which private channel — `routes/channels.php`.
 *
 * A user must never be able to subscribe to another user's channel, or to a
 * takeoff project they do not own, merely by changing the id in the
 * WebSocket subscribe request. These hit the real `/broadcasting/auth`
 * endpoint Echo/Pusher-js call, the same one a browser's socket actually
 * negotiates against — not the channel closures in isolation.
 */
class RealtimeChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The test environment defaults `BROADCAST_CONNECTION` to `null` so the
     * rest of the suite never makes a real network call when a `ShouldBroadcast`
     * event fires with `QUEUE_CONNECTION=sync` — but that driver's `auth()`
     * is a deliberate no-op (see `NullBroadcaster`), so it never even
     * consults `routes/channels.php`.
     *
     * Channel authorization is otherwise real Pusher-protocol logic (Reverb
     * speaks that protocol), and signing a channel auth response is a local
     * HMAC operation, not a network call — safe to exercise here. Switching
     * `broadcasting.default` alone is not enough, though: `Broadcast::channel()`
     * registers each pattern on whichever driver *instance* was current when
     * `routes/channels.php` first loaded (at boot, while still on `null`), so
     * the patterns have to be re-registered against the now-current driver.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'reverb']);
        require base_path('routes/channels.php');
    }

    private function auth(string $channel): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-{$channel}",
            'socket_id' => '1234.5678',
        ]);
    }

    public function test_a_user_can_authorize_their_own_channel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->auth("user.{$user->id}")->assertOk();
    }

    public function test_a_user_cannot_authorize_someone_elses_channel(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->auth("user.{$other->id}")->assertForbidden();
    }

    public function test_a_guest_cannot_authorize_any_user_channel(): void
    {
        $user = User::factory()->create();

        $this->auth("user.{$user->id}")->assertForbidden();
    }

    public function test_the_owner_of_a_project_can_authorize_its_channel(): void
    {
        $owner = User::factory()->create();
        $project = Project::create(['user_id' => $owner->id, 'name' => 'Owned Project', 'client' => 'Acme', 'status' => 'draft']);

        $this->actingAs($owner)->auth("project.{$project->id}")->assertOk();
    }

    public function test_a_user_cannot_authorize_someone_elses_project_channel(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::create(['user_id' => $owner->id, 'name' => 'Owned Project', 'client' => 'Acme', 'status' => 'draft']);

        $this->actingAs($intruder)->auth("project.{$project->id}")->assertForbidden();
    }

    public function test_a_nonexistent_project_channel_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->auth('project.999999')->assertForbidden();
    }

    public function test_any_signed_in_user_can_authorize_an_existing_jobs_channel(): void
    {
        $job = Job::create([
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside Office Renovation',
            'client' => 'Riverside Properties LLC',
            'status' => 'in-progress',
        ]);
        $anyUser = User::factory()->create(['role' => 'Electrician']);

        $this->actingAs($anyUser)->auth("job.{$job->id}")->assertOk();
    }

    public function test_a_nonexistent_jobs_channel_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->auth('job.999999')->assertForbidden();
    }

    public function test_a_guest_cannot_authorize_a_jobs_channel(): void
    {
        $job = Job::create([
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside Office Renovation',
            'client' => 'Riverside Properties LLC',
            'status' => 'in-progress',
        ]);

        $this->auth("job.{$job->id}")->assertForbidden();
    }
}
