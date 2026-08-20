<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_counts_its_tiles_from_the_database(): void
    {
        $this->seed(DemoDataSeeder::class);
        $user = User::where('email', 'demo@breeze.ai')->firstOrFail();

        $this->actingAs($user)
            ->get('/home')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home')
                ->has('summary', 3)
                ->where('summary.0.label', 'Active Jobs')
                ->where('summary.0.value', 8)
                ->has('activity', 5)
                ->has('notificationFeed', 4)
                ->has('schedule', 3)
                ->has('performance', 12));
    }

    public function test_the_root_url_serves_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page->component('Home'));
    }

    public function test_the_shell_shares_the_user_and_their_notifications(): void
    {
        $this->seed(DemoDataSeeder::class);
        $user = User::where('email', 'demo@breeze.ai')->firstOrFail();

        // `seedNotifications()` fires the app's real Notification classes
        // against seeded data rather than writing fake rows — in a fresh
        // seed that's a job-cost overrun and a paid invoice for this user
        // (the job-assigned and document-shared notifications go to Jordan,
        // and no AiResult exists in a fresh seed for a takeoff notification).
        $this->actingAs($user)
            ->get('/home')
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.name', 'Alex Morgan')
                ->where('auth.user.initials', 'AM')
                ->where('auth.user.role', 'Project Manager')
                ->has('notifications', 2)
                ->where('unreadNotificationCount', 2));
    }

    public function test_feed_rows_carry_an_icon_key_the_client_can_resolve(): void
    {
        $this->seed(DemoDataSeeder::class);
        $user = User::where('email', 'demo@breeze.ai')->firstOrFail();

        $this->actingAs($user)
            ->get('/home')
            ->assertInertia(fn (Assert $page) => $page
                ->where('activity.0.icon', 'file-text')
                ->where('activity.0.tile', 'lilac')
                ->has('activity.0.segments'));
    }

    public function test_the_state_screens_render(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/empty')
            ->assertInertia(fn (Assert $page) => $page->component('EmptyState'));

        $this->actingAs($user)->get('/error')
            ->assertInertia(fn (Assert $page) => $page->component('ErrorState'));
    }

    public function test_an_unknown_url_renders_the_not_found_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/no-such-page', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
            ->assertStatus(404);
    }
}
