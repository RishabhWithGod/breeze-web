<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\CompanyProfile;
use App\Models\FeedItem;
use App\Models\Job;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Every figure on the dashboard is the signed-in company's own — a brand-new
 * account starts at zero, whatever other companies have.
 */
class DashboardScopeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: CompanyProfile, 1: User} */
    private function company(string $name): array
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $company = CompanyProfile::create([
            'user_id' => $manager->id, 'name' => $name, 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        $manager->forceFill(['company_id' => $company->id])->save();

        return [$company, $manager->fresh()];
    }

    /** A company with one of everything the dashboard counts. */
    private function busyCompany(): User
    {
        [, $manager] = $this->company('Busy Co');

        Job::create(['user_id' => $manager->id, 'name' => 'Active One', 'client' => 'Acme', 'status' => 'in-progress']);
        // One takeoff the AI is really working on...
        $working = Project::create(['user_id' => $manager->id, 'name' => 'Processing One', 'client' => 'Acme', 'status' => 'processing']);
        $workingUpload = $working->uploads()->create([
            'user_id' => $manager->id, 'name' => 'A-1.pdf', 'format' => 'PDF', 'size_bytes' => 1024, 'status' => 'completed',
        ]);
        AiJob::create(['project_id' => $working->id, 'upload_id' => $workingUpload->id, 'user_id' => $manager->id, 'status' => AiJob::STATUS_PROCESSING]);
        // ...and two that only look pending: a project just opened, and one marked
        // processing that nothing was ever sent for. Neither is a takeoff in progress.
        Project::create(['user_id' => $manager->id, 'name' => 'Just Opened', 'client' => 'Acme', 'status' => 'draft']);
        Project::create(['user_id' => $manager->id, 'name' => 'Marked Processing', 'client' => 'Acme', 'status' => 'processing']);
        $project = Project::create(['user_id' => $manager->id, 'name' => 'Reviewed One', 'client' => 'Acme', 'status' => 'completed']);
        $upload = $project->uploads()->create([
            'user_id' => $manager->id, 'name' => 'E-101.pdf', 'format' => 'PDF', 'size_bytes' => 1024, 'status' => 'completed',
        ]);
        AiResult::create([
            'ai_job_id' => AiJob::create([
                'project_id' => $project->id, 'upload_id' => $upload->id, 'user_id' => $manager->id, 'status' => 'completed',
            ])->id,
            'project_id' => $project->id,
            'upload_id' => $upload->id,
            'original_payload' => [],
            'review_status' => AiResult::REVIEW_PENDING,
            'received_at' => now(),
        ]);
        foreach (['sent' => 'EST-1001', 'draft' => 'EST-1002'] as $status => $number) {
            DB::table('estimates')->insert([
                'user_id' => $manager->id, 'number' => $number, 'client' => 'Acme', 'project' => 'P', 'issued_on' => '2026-01-01',
                'amount' => 100, 'grand_total' => 100, 'kind' => 'standalone', 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        FeedItem::create(['scope' => FeedItem::DASHBOARD_ACTIVITY, 'user_id' => $manager->id, 'segments' => [['text' => 'Busy did a thing']], 'icon' => 'briefcase', 'tile' => 'butter', 'position' => 0]);

        return $manager;
    }

    public function test_a_brand_new_company_starts_at_zero_whatever_others_have(): void
    {
        $this->busyCompany();
        [, $fresh] = $this->company('Fresh Co');

        $this->actingAs($fresh)->get('/home')->assertInertia(fn (Assert $page) => $page
            ->where('summary.0.label', 'Active Jobs')->where('summary.0.value', 0)
            ->where('summary.1.label', 'Pending AI Takeoffs')->where('summary.1.value', 0)
            ->where('summary.2.label', 'Estimates Awaiting Approval')->where('summary.2.value', 0)
            ->has('activity', 0)
            ->has('notificationFeed', 0)
            ->has('schedule', 0)
            ->has('draftEstimates', 0)
            ->has('reviewsNeedingAttention', 0)
            ->where('billing.totalOutstanding', 0)
            ->where('billing.overdue', 0)
            ->where('billing.paidThisMonth', 0)
            ->where('billing.averageDaysToPay', null)
            ->where('performance', fn ($series) => collect($series)->every(fn ($month) => $month['value'] === null && $month['count'] === 0)));
    }

    public function test_a_company_counts_its_own_work(): void
    {
        $busy = $this->busyCompany();

        $this->actingAs($busy)->get('/home')->assertInertia(fn (Assert $page) => $page
            ->where('summary.0.value', 1)
            ->where('summary.1.value', 1)
            ->where('summary.2.value', 1)
            ->has('activity', 1)
            ->has('draftEstimates', 1)
            ->has('reviewsNeedingAttention', 1));
    }

    public function test_every_manager_of_the_company_sees_the_same_figures(): void
    {
        $busy = $this->busyCompany();
        $colleague = User::factory()->create(['role' => 'Project Manager']);
        $colleague->forceFill(['company_id' => $busy->company_id])->save();

        $this->actingAs($colleague->fresh())->get('/home')->assertInertia(fn (Assert $page) => $page
            ->where('summary.0.value', 1)
            ->where('summary.1.value', 1)
            ->where('summary.2.value', 1)
            ->has('activity', 1)
            ->has('reviewsNeedingAttention', 1));
    }

    public function test_the_tiles_agree_with_the_lists_they_link_to(): void
    {
        $busy = $this->busyCompany();

        $this->actingAs($busy)->get('/home')->assertInertia(fn (Assert $page) => $page->where('summary.0.href', '/jobs'));
        $this->actingAs($busy)->get('/jobs')->assertInertia(fn (Assert $page) => $page->has('jobs.data', 1));
        $this->actingAs($busy)->get('/estimates')->assertOk();
        $this->actingAs($busy)->get('/ai-takeoff?status=processing')->assertOk();
    }
}
