<?php

namespace Tests\Feature\Api;

use App\Models\CompanyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileRolesPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
    }

    private function manager(): User
    {
        $user = User::factory()->create(['role' => 'Project Manager']);
        $company = CompanyProfile::create([
            'user_id' => $user->id, 'name' => 'Volt & Co', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        $user->forceFill(['company_id' => $company->id])->save();

        return $user->fresh();
    }

    public function test_manager_reads_the_matrix(): void
    {
        $this->getJson('/api/v1/roles-permissions', $this->auth($this->manager()))
            ->assertOk()
            ->assertJsonStructure(['data' => ['roles', 'modules', 'granted', 'defaults', 'customised']]);
    }

    public function test_manager_saves_and_me_reflects_it(): void
    {
        $manager = $this->manager();
        $this->putJson('/api/v1/roles-permissions', ['granted' => [
            'foreman' => ['clients.view'], 'journeyman' => [], 'apprentice' => [],
        ]], $this->auth($manager))->assertOk();

        $this->app['auth']->forgetGuards();
        $foreman = User::factory()->create(['role' => 'Foreman', 'company_id' => $manager->company_id]);
        $this->getJson('/api/v1/auth/me', $this->auth($foreman))
            ->assertOk()
            ->assertJsonPath('data.permissions', ['clients.view']);
    }

    public function test_foreman_without_manage_roles_is_refused(): void
    {
        $manager = $this->manager();
        $foreman = User::factory()->create(['role' => 'Foreman', 'company_id' => $manager->company_id]);
        $this->getJson('/api/v1/roles-permissions', $this->auth($foreman))->assertForbidden();
    }

    public function test_user_without_company_is_refused(): void
    {
        $user = User::factory()->create(['role' => 'Project Manager', 'company_id' => null]);
        $this->getJson('/api/v1/roles-permissions', $this->auth($user))->assertForbidden();
    }

    public function test_the_matrix_governs_office_routes_but_not_field_work(): void
    {
        $manager = $this->manager();
        $this->putJson('/api/v1/roles-permissions', ['granted' => [
            'foreman' => ['clients.view', 'jobs.view'], 'journeyman' => [], 'apprentice' => [],
        ]], $this->auth($manager))->assertOk();
        $this->app['auth']->forgetGuards();

        $foreman = User::factory()->create(['role' => 'Foreman', 'company_id' => $manager->company_id, 'status' => User::STATUS_ACTIVE]);
        $headers = $this->auth($foreman);

        $this->getJson('/api/v1/clients', $headers)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/clients', ['name' => 'Nope Co'], $headers)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/projects', $headers)->assertForbidden();
        $this->app['auth']->forgetGuards();
        // Field work is not behind the matrix.
        $this->getJson('/api/v1/timer', $headers)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/jobs', $headers)->assertOk();
    }
}
