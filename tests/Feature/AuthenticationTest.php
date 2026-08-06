<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/home')->assertRedirect('/login');
        $this->get('/ai-takeoff')->assertRedirect('/login');
        $this->get('/jobs')->assertRedirect('/login');
    }

    public function test_login_screen_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('data-page', escape: false);
    }

    public function test_the_seeded_demo_account_can_sign_in(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->post('/login', [
            'email' => 'demo@breeze.ai',
            'password' => 'breeze123',
        ])->assertRedirect('/home');

        $this->assertAuthenticated();
        $this->assertSame('Alex Morgan', Auth::user()->name);
        $this->assertSame('AM', Auth::user()->initials);
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->from('/login')
            ->post('/login', [
                'email' => 'demo@breeze.ai',
                'password' => 'wrong-password',
            ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_remember_me_stores_the_email_for_next_time(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->post('/login', [
            'email' => 'demo@breeze.ai',
            'password' => 'breeze123',
            'remember' => true,
        ])->assertCookie('breeze_remembered_email', 'demo@breeze.ai');
    }

    public function test_signed_in_users_are_kept_off_the_login_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/login')
            ->assertRedirect();
    }

    public function test_users_can_sign_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_a_reset_request_always_reports_success(): void
    {
        // Never reveals whether the address exists.
        $this->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertSessionHas('resetLinkSentTo', 'nobody@example.com')
            ->assertSessionHasNoErrors();
    }
}
