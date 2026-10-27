<?php

namespace Tests\Feature;

use App\Models\CompanyProfile;
use App\Models\Foreman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A member's email on the crew register is their login: correcting one corrects the other. */
class MemberLoginEmailTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $company = CompanyProfile::create([
            'user_id' => $manager->id, 'name' => 'Volt & Co', 'business_address' => '1 Main St',
            'primary_contact' => 'Alex', 'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        $manager->forceFill(['company_id' => $company->id])->save();

        return $manager->fresh();
    }

    private function member(User $manager): Foreman
    {
        $this->actingAs($manager)->post(route('foremen.store'), [
            'name' => 'Dana Wu', 'role' => 'foreman',
            'email' => 'dana@example.com', 'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x',
        ])->assertSessionHasNoErrors();

        return Foreman::withoutGlobalScopes()->where('name', 'Dana Wu')->sole();
    }

    public function test_editing_on_web_changes_the_login_email_too(): void
    {
        $manager = $this->manager();
        $dana = $this->member($manager);

        $this->actingAs($manager)->put(route('foremen.update', $dana), [
            'name' => 'Dana Wu', 'role' => 'foreman', 'email' => 'Dana.New@Example.com',
        ])->assertSessionHasNoErrors();

        $this->assertSame('dana.new@example.com', User::findOrFail($dana->user_id)->email);
        $this->assertNull(User::where('email', 'dana@example.com')->first());
    }

    public function test_editing_from_the_app_changes_the_login_email_too(): void
    {
        $manager = $this->manager();
        $dana = $this->member($manager);

        Sanctum::actingAs($manager);
        $this->putJson("/api/v1/team/{$dana->id}", [
            'name' => 'Dana Wu', 'role' => 'foreman', 'email' => 'dana.app@example.com',
        ])->assertOk();

        $this->assertSame('dana.app@example.com', User::findOrFail($dana->user_id)->email);
        $this->postJson('/api/v1/auth/login', ['email' => 'dana.app@example.com', 'password' => 'Str0ng-Passw0rd!x'])->assertOk();
    }

    public function test_an_email_already_used_by_another_login_is_refused(): void
    {
        $manager = $this->manager();
        $dana = $this->member($manager);
        User::factory()->create(['email' => 'taken@example.com']);

        Sanctum::actingAs($manager);
        $this->putJson("/api/v1/team/{$dana->id}", [
            'name' => 'Dana Wu', 'role' => 'foreman', 'email' => 'taken@example.com',
        ])->assertUnprocessable();

        $this->assertSame('dana@example.com', User::findOrFail($dana->user_id)->email);
    }

    public function test_saving_without_changing_the_email_keeps_the_login(): void
    {
        $manager = $this->manager();
        $dana = $this->member($manager);

        Sanctum::actingAs($manager);
        $this->putJson("/api/v1/team/{$dana->id}", [
            'name' => 'Dana W.', 'role' => 'foreman', 'email' => 'dana@example.com',
        ])->assertOk();

        $this->assertSame('dana@example.com', User::findOrFail($dana->user_id)->email);
    }
}
