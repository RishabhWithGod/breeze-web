<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Estimate;
use App\Models\Foreman;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\JobAssignment;
use App\Models\Project;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Notification Center: the real events that generate notifications, the
 * Notification Center page's tabs/filters/pagination, mark-as-read, and the
 * permission boundary keeping one user's notifications away from another's.
 */
class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'Project Manager']);
        $this->other = User::factory()->create(['role' => 'Electrician']);
    }

    public function test_assigning_a_job_notifies_the_assignee_with_a_real_action(): void
    {
        $job = $this->makeJob();
        $member = TeamMember::create(['name' => $this->other->name, 'initials' => 'EL', 'role' => 'Electrician', 'user_id' => $this->other->id]);

        $this->actingAs($this->manager)->post("/jobs/{$job->id}/assignments", [
            'role' => JobAssignment::ROLE_ELECTRICIAN,
            'team_member_id' => $member->id,
        ])->assertRedirect();

        $notification = AppNotification::where('user_id', $this->other->id)->where('type', 'job-assigned')->first();
        $this->assertNotNull($notification);
        $this->assertSame('jobs', AppNotification::categoryFor($notification->type));
        $this->assertEquals([['label' => 'View Job', 'href' => "/jobs/{$job->id}"]], $notification->data['actions']);

        // The manager who made the assignment doesn't notify themselves.
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $this->manager->id, 'type' => 'job-assigned']);
    }

    public function test_manually_approving_an_estimate_notifies_the_jobs_project_manager(): void
    {
        $job = $this->makeJob();
        $pmMember = TeamMember::create(['name' => 'Pat PM', 'initials' => 'PP', 'role' => 'Project Manager', 'user_id' => $this->manager->id]);
        JobAssignment::create([
            'job_id' => $job->id, 'team_member_id' => $pmMember->id, 'user_id' => $this->manager->id,
            'role' => JobAssignment::ROLE_PROJECT_MANAGER, 'name' => 'Pat PM', 'assigned_at' => now(),
        ]);
        $estimate = $this->makeEstimate($job);

        $this->actingAs($this->other)->put("/estimates/{$estimate->id}", [
            'project_id' => $this->makeClient($estimate->client)->id,
            'status' => 'approved',
            'issued_on' => now()->toDateString(),
            'markup_pct' => 0,
            'tax_pct' => 0,
        ])->assertRedirect();

        $notification = AppNotification::where('user_id', $this->manager->id)->where('type', 'estimate-approved')->first();
        $this->assertNotNull($notification);
        $this->assertSame('estimates', AppNotification::categoryFor($notification->type));
    }

    public function test_marking_an_invoice_paid_notifies_its_creator(): void
    {
        $this->actingAs($this->manager)->post('/invoices', [
            'project_id' => $this->makeClient()->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => null,
            'tax_pct' => 0,
        ])->assertSessionHasNoErrors();
        $invoice = Invoice::latest('id')->first();

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/items", [
            'description' => 'Panel install', 'quantity' => 1, 'unit_price' => 500,
        ]);
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/send")->assertRedirect();

        // A different manager marks it paid — the creator should hear about it.
        $secondManager = User::factory()->create(['role' => 'Admin']);
        $this->actingAs($secondManager)->post("/invoices/{$invoice->id}/mark-paid")->assertRedirect();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->manager->id,
            'type' => 'invoice-paid',
        ]);
        // The creator marking their own invoice paid doesn't self-notify.
        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $secondManager->id,
            'type' => 'invoice-paid',
        ]);
    }

    public function test_the_center_defaults_to_all_and_the_tabs_filter_by_read_state(): void
    {
        AppNotification::create(['user_id' => $this->manager->id, 'type' => 'general', 'title' => 'A', 'detail' => 'a']);
        AppNotification::create(['user_id' => $this->manager->id, 'type' => 'general', 'title' => 'B', 'detail' => 'b', 'read_at' => now()]);

        $this->actingAs($this->manager)->get('/notifications')
            ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 2)
                ->where('tabCounts.all', 2)->where('tabCounts.unread', 1)->where('tabCounts.read', 1));

        $this->actingAs($this->manager)->get('/notifications?tab=unread')
            ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 1)
                ->where('notifications.data.0.title', 'A'));

        $this->actingAs($this->manager)->get('/notifications?tab=read')
            ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 1)
                ->where('notifications.data.0.title', 'B'));
    }

    public function test_filtering_by_category_only_returns_that_categorys_notifications(): void
    {
        AppNotification::create(['user_id' => $this->manager->id, 'type' => 'job-cost-overrun', 'title' => 'Overrun', 'detail' => '...']);
        AppNotification::create(['user_id' => $this->manager->id, 'type' => 'job-assigned', 'title' => 'Assigned', 'detail' => '...']);

        $this->actingAs($this->manager)->get('/notifications?category=job-costing')
            ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 1)
                ->where('notifications.data.0.title', 'Overrun'));

        $this->actingAs($this->manager)->get('/notifications?category=jobs')
            ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 1)
                ->where('notifications.data.0.title', 'Assigned'));
    }

    public function test_clicking_a_notification_marks_it_read_and_redirects_to_the_action(): void
    {
        $notification = AppNotification::create([
            'user_id' => $this->manager->id, 'type' => 'general', 'title' => 'A', 'detail' => 'a',
            'link' => '/jobs',
        ]);

        $response = $this->actingAs($this->manager)
            ->post("/notifications/{$notification->id}/read", ['redirect' => '/jobs']);

        $response->assertRedirect('/jobs');
        $this->assertNotNull($notification->refresh()->read_at);
    }

    public function test_mark_read_rejects_an_external_redirect(): void
    {
        $notification = AppNotification::create(['user_id' => $this->manager->id, 'type' => 'general', 'title' => 'A', 'detail' => 'a']);

        $response = $this->actingAs($this->manager)
            ->post("/notifications/{$notification->id}/read", ['redirect' => '//evil.example.com']);

        $response->assertRedirect('/notifications');
    }

    public function test_mark_all_read_updates_every_unread_notification_for_that_user_only(): void
    {
        AppNotification::create(['user_id' => $this->manager->id, 'type' => 'general', 'title' => 'A', 'detail' => 'a']);
        AppNotification::create(['user_id' => $this->manager->id, 'type' => 'general', 'title' => 'B', 'detail' => 'b']);
        $othersNotification = AppNotification::create(['user_id' => $this->other->id, 'type' => 'general', 'title' => 'C', 'detail' => 'c']);

        $this->actingAs($this->manager)->post('/notifications/read-all')->assertRedirect();

        $this->assertSame(0, AppNotification::where('user_id', $this->manager->id)->unread()->count());
        $this->assertNull($othersNotification->refresh()->read_at);
    }

    public function test_a_user_cannot_mark_someone_elses_notification_as_read(): void
    {
        $notification = AppNotification::create(['user_id' => $this->other->id, 'type' => 'general', 'title' => 'A', 'detail' => 'a']);

        $this->actingAs($this->manager)->post("/notifications/{$notification->id}/read")->assertForbidden();
        $this->assertNull($notification->refresh()->read_at);
    }

    public function test_a_user_only_sees_their_own_notifications(): void
    {
        AppNotification::create(['user_id' => $this->manager->id, 'type' => 'general', 'title' => 'Mine', 'detail' => 'a']);
        AppNotification::create(['user_id' => $this->other->id, 'type' => 'general', 'title' => 'Theirs', 'detail' => 'b']);

        $this->actingAs($this->manager)->get('/notifications')
            ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 1)
                ->where('notifications.data.0.title', 'Mine'));
    }

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

    /** A client to raise an estimate or invoice for. Clients are projects. */
    private function makeClient(string $name = 'Apex Construction'): Project
    {
        return Project::create([
            'user_id' => $this->manager->id,
            'name' => $name,
            'client' => $name,
            'status' => 'draft',
        ]);
    }

    private function makeEstimate(Job $job): Estimate
    {
        return Estimate::create([
            'job_id' => $job->id,
            'number' => 'EST-3001',
            'client' => $job->client,
            'project' => 'Panel upgrade',
            'issued_on' => now()->toDateString(),
            'amount' => 1000,
            'status' => 'draft',
        ]);
    }
}
