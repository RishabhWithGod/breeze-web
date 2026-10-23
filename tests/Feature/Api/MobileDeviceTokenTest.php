<?php

namespace Tests\Feature\Api;

use App\Jobs\SendPushNotification;
use App\Models\AppNotification;
use App\Models\DeviceToken;
use App\Models\User;
use App\Notifications\Channels\AppNotificationChannel;
use App\Services\Push\FcmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MobileDeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_a_phone_registers_and_is_taken_back_on_sign_out(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $h = $this->as($user);

        $this->postJson('/api/v1/device-tokens', ['token' => 'tok-1', 'platform' => 'ios'], $h)->assertOk();
        $this->postJson('/api/v1/device-tokens', ['token' => 'tok-1', 'platform' => 'ios'], $h)->assertOk();
        $this->assertSame(1, DeviceToken::count());

        $this->deleteJson('/api/v1/device-tokens', ['token' => 'tok-1'], $h)->assertOk();
        $this->assertSame(0, DeviceToken::count());
    }

    public function test_a_phone_that_switches_account_moves_to_the_new_user(): void
    {
        $first = User::factory()->create(['role' => 'Electrician']);
        $second = User::factory()->create(['role' => 'Electrician']);

        $this->postJson('/api/v1/device-tokens', ['token' => 'shared', 'platform' => 'android'], $this->as($first))->assertOk();
        // Same app process serves both requests; drop the guard's cached user.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/device-tokens', ['token' => 'shared', 'platform' => 'android'], $this->as($second))->assertOk();

        $this->assertSame($second->id, DeviceToken::where('token', 'shared')->value('user_id'));
    }

    public function test_one_user_cannot_remove_another_users_token(): void
    {
        $owner = User::factory()->create(['role' => 'Electrician']);
        DeviceToken::create(['user_id' => $owner->id, 'token' => 'mine', 'platform' => 'ios']);

        $other = User::factory()->create(['role' => 'Electrician']);
        $this->deleteJson('/api/v1/device-tokens', ['token' => 'mine'], $this->as($other))->assertOk();

        $this->assertSame(1, DeviceToken::count());
    }

    public function test_a_new_notification_queues_a_push(): void
    {
        Bus::fake();
        $user = User::factory()->create(['role' => 'Electrician']);
        $notification = AppNotification::create(['user_id' => $user->id, 'type' => 'job-assigned', 'title' => 'x', 'detail' => 'y']);

        (new AppNotificationChannel)->send($user, new class extends Notification
        {
            public function toAppNotification($n): array
            {
                return ['title' => 'New job', 'detail' => 'Panel upgrade', 'type' => 'job-assigned'];
            }
        });

        Bus::assertDispatched(SendPushNotification::class);
        $this->assertNotNull($notification);
    }

    public function test_push_is_sent_to_the_users_phones_when_fcm_is_configured(): void
    {
        $credentials = storage_path('framework/testing/fcm.json');
        @mkdir(dirname($credentials), 0777, true);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        file_put_contents($credentials, json_encode(['client_email' => 'svc@test.iam', 'private_key' => $pem]));
        config(['services.fcm.project_id' => 'demo', 'services.fcm.credentials' => $credentials]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at']),
            'fcm.googleapis.com/*' => Http::response(['name' => 'ok']),
        ]);

        $user = User::factory()->create(['role' => 'Electrician']);
        DeviceToken::create(['user_id' => $user->id, 'token' => 'dev-1', 'platform' => 'ios']);
        $n = AppNotification::create(['user_id' => $user->id, 'type' => 'job-assigned', 'title' => 'New job', 'detail' => 'Panel', 'data' => ['jobId' => 7]]);

        (new SendPushNotification($n->id))->handle(app(FcmClient::class));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'messages:send')
            && $r['message']['token'] === 'dev-1'
            && $r['message']['data']['jobId'] === '7'
            && $r['message']['notification']['title'] === 'New job');

        @unlink($credentials);
    }

    public function test_a_push_carries_where_the_app_should_open(): void
    {
        $credentials = storage_path('framework/testing/fcm.json');
        @mkdir(dirname($credentials), 0777, true);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        file_put_contents($credentials, json_encode(['client_email' => 'svc@test.iam', 'private_key' => $pem]));
        config(['services.fcm.project_id' => 'demo', 'services.fcm.credentials' => $credentials]);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at']),
            'fcm.googleapis.com/*' => Http::response(['name' => 'ok']),
        ]);

        $user = User::factory()->create(['role' => 'Electrician']);
        DeviceToken::create(['user_id' => $user->id, 'token' => 'dev-2', 'platform' => 'android']);
        $n = AppNotification::create(['user_id' => $user->id, 'type' => 'job-assigned', 'title' => 'New job', 'detail' => 'Panel', 'link' => '/jobs/42']);

        (new SendPushNotification($n->id))->handle(app(FcmClient::class));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'messages:send')
            && $r['message']['data']['target_screen'] === 'job'
            && $r['message']['data']['target_id'] === '42');

        @unlink($credentials);
    }

    public function test_a_dead_token_is_removed(): void
    {
        $credentials = storage_path('framework/testing/fcm.json');
        @mkdir(dirname($credentials), 0777, true);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        file_put_contents($credentials, json_encode(['client_email' => 'svc@test.iam', 'private_key' => $pem]));
        config(['services.fcm.project_id' => 'demo', 'services.fcm.credentials' => $credentials]);
        Cache::forget('fcm.access_token');

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at']),
            'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'NOT_FOUND']], 404),
        ]);

        $user = User::factory()->create(['role' => 'Electrician']);
        DeviceToken::create(['user_id' => $user->id, 'token' => 'dead', 'platform' => 'android']);
        $n = AppNotification::create(['user_id' => $user->id, 'type' => 'general', 'title' => 'x', 'detail' => 'y']);

        (new SendPushNotification($n->id))->handle(app(FcmClient::class));

        $this->assertSame(0, DeviceToken::count());
        @unlink($credentials);
    }
}
