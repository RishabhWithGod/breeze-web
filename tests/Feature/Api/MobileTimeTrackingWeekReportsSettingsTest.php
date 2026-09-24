<?php

namespace Tests\Feature\Api;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mobile's Week / Reports / Settings — full parity with web's
 * `TimeTrackingController::week()`, `TimeTrackingReportController::index()`
 * and `TimeTrackingSettingController` (same builders/policies), just JSON
 * instead of an Inertia page.
 */
class MobileTimeTrackingWeekReportsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(array $attributes = []): Job
    {
        return Job::create([
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside Office Renovation',
            'client' => 'Riverside Properties LLC',
            'status' => 'in-progress',
            ...$attributes,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    // --- Week ---------------------------------------------------------------

    public function test_week_returns_the_signed_in_users_own_seven_day_grid(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        TimeEntry::create([
            'job_id' => $job->id, 'user_id' => $user->id, 'date' => now()->toDateString(),
            'hours' => 4, 'regular_hours' => 4, 'status' => TimeEntry::STATUS_DRAFT, 'source' => TimeEntry::SOURCE_MANUAL,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/time-tracking/week')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'week' => ['weekStart', 'weekEnd', 'days', 'totals'],
                    'prevWeekDate', 'nextWeekDate', 'thisWeekDate',
                ],
            ]);

        $this->assertEqualsWithDelta(4.0, $response->json('data.week.totals.total'), 0.001);
    }

    public function test_week_requires_authentication(): void
    {
        $this->getJson('/api/v1/time-tracking/week')->assertUnauthorized();
    }

    // --- Reports --------------------------------------------------------------

    public function test_reports_requires_manager_role(): void
    {
        $electrician = User::factory()->create(['role' => 'Electrician']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($electrician))
            ->getJson('/api/v1/time-tracking/reports')
            ->assertStatus(403);
    }

    public function test_a_manager_can_view_reports_with_approved_totals_only(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $electrician = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob(['user_id' => $manager->id]);

        TimeEntry::create([
            'job_id' => $job->id, 'user_id' => $electrician->id, 'date' => now()->toDateString(),
            'hours' => 5, 'billable' => true, 'status' => TimeEntry::STATUS_APPROVED, 'source' => TimeEntry::SOURCE_MANUAL,
        ]);
        // A submitted (not yet approved) entry must never count toward the report.
        TimeEntry::create([
            'job_id' => $job->id, 'user_id' => $electrician->id, 'date' => now()->toDateString(),
            'hours' => 9, 'status' => TimeEntry::STATUS_SUBMITTED, 'source' => TimeEntry::SOURCE_MANUAL,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->getJson('/api/v1/time-tracking/reports')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'range', 'byJob', 'byEmployee', 'byTask',
                    'billableSplit' => ['billable', 'nonBillable'],
                    'overtimeTotal', 'laborCostTotal', 'estimatedVsActual', 'canViewCosts',
                ],
            ]);

        $this->assertEqualsWithDelta(5.0, $response->json('data.billableSplit.billable'), 0.001);
        $this->assertSame(1, count($response->json('data.byJob')));
    }

    // --- Settings -------------------------------------------------------------

    public function test_settings_requires_admin_role(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->getJson('/api/v1/time-tracking/settings')
            ->assertStatus(403);
    }

    public function test_an_admin_can_view_and_update_settings(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($admin))
            ->getJson('/api/v1/time-tracking/settings')
            ->assertOk()
            ->assertJsonStructure(['data' => ['settings' => ['regularDailyHours', 'regularWeeklyHours', 'overtimeMultiplier', 'timezone'], 'timezones']]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($admin))
            ->putJson('/api/v1/time-tracking/settings', [
                'regular_daily_hours' => 10,
                'regular_weekly_hours' => 45,
                'overtime_multiplier' => 1.5,
                'weekend_overtime' => true,
                'holiday_overtime' => true,
                'timezone' => 'America/New_York',
            ])
            ->assertOk();

        $this->assertEqualsWithDelta(10.0, $response->json('data.settings.regularDailyHours'), 0.001);
        $this->assertDatabaseHas('time_tracking_settings', ['regular_daily_hours' => 10, 'updated_by' => $admin->id]);
    }
}
