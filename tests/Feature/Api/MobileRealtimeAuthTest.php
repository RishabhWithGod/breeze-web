<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileRealtimeAuthTest extends TestCase
{
    use RefreshDatabase;

    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
    }

    public function test_a_phone_can_only_be_signed_for_its_own_private_channel(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => '1']);
        // The channel rules are registered on whichever driver is default at boot; point them at reverb.
        \Illuminate\Support\Facades\Broadcast::purge();
        require base_path('routes/channels.php');
        $me = User::factory()->create();
        $other = User::factory()->create();

        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => "private-user.{$me->id}"], $this->auth($me))
            ->assertOk()->assertJsonStructure(['auth']);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => "private-user.{$other->id}"], $this->auth($me))
            ->assertForbidden();
    }

    public function test_it_needs_a_signed_in_person(): void
    {
        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-user.1'])->assertUnauthorized();
    }
}
