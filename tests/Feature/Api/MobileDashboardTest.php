<?php

namespace Tests\Feature\Api;

use App\Models\CompanyProfile;
use App\Models\Estimate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileDashboardTest extends TestCase
{
    use RefreshDatabase;

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

    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    public function test_manager_gets_every_panel(): void
    {
        $this->getJson('/api/v1/dashboard', $this->auth($this->manager()))
            ->assertOk()
            ->assertJsonCount(3, 'data.summary')
            ->assertJsonStructure(['data' => [
                'summary', 'schedule', 'reviewsNeedingAttention', 'draftEstimates',
                'billing' => ['totalOutstanding', 'overdue', 'paidThisMonth', 'averageDaysToPay'],
                'notificationFeed',
            ]]);
    }

    public function test_a_journeyman_only_gets_what_the_matrix_opens(): void
    {
        $manager = $this->manager();
        $journeyman = User::factory()->create(['role' => 'Journeyman', 'company_id' => $manager->company_id, 'status' => User::STATUS_ACTIVE]);

        $response = $this->getJson('/api/v1/dashboard', $this->auth($journeyman))->assertOk();

        $this->assertSame(['sum_jobs'], collect($response->json('data.summary'))->pluck('id')->all());
        $this->assertNull($response->json('data.billing'));
        $this->assertNull($response->json('data.draftEstimates'));
        $this->assertNull($response->json('data.reviewsNeedingAttention'));
        $this->assertNull($response->json('data.schedule'));
    }
}
