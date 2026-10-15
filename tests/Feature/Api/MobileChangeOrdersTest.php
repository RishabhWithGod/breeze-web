<?php

namespace Tests\Feature\Api;

use App\Models\ChangeOrder;
use App\Models\CompanyProfile;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The app's office Change Orders screens follow web's own rules: a manager
 * sees and decides every change order, a foreman raises and changes theirs.
 */
class MobileChangeOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $foremanUser;

    private Job $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'Project Manager', 'status' => User::STATUS_ACTIVE]);
        $company = CompanyProfile::create([
            'user_id' => $this->manager->id, 'name' => 'Volt & Co', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        $this->manager->forceFill(['company_id' => $company->id])->save();
        $this->manager = $this->manager->fresh();
        $this->foremanUser = User::factory()->create(['role' => 'Foreman', 'company_id' => $company->id, 'status' => User::STATUS_ACTIVE]);
        $foreman = (new Foreman(['name' => 'Dana Wu', 'initials' => 'DW', 'role' => 'foreman']))
            ->forceFill(['user_id' => $this->foremanUser->id, 'company_id' => $company->id]);
        $foreman->save();
        $this->job = Job::create([
            'user_id' => $this->manager->id,
            'name' => 'Riverside Medical Center',
            'client' => 'Riverside',
            'status' => 'in-progress',
            'foreman_id' => $foreman->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'job_id' => $this->job->id,
            'description' => 'Add dedicated circuit for MRI suite',
            'source' => 'office',
            'markup_pct' => 20,
            'lines' => [
                ['kind' => 'material', 'description' => '#6 copper wire', 'quantity' => 100, 'unit' => 'LF', 'unit_cost' => 12.5],
                ['kind' => 'labor', 'description' => 'Journeyman install', 'quantity' => 16, 'unit' => 'HR', 'unit_cost' => 50],
            ],
            ...$overrides,
        ];
    }

    private function as(User $user): self
    {
        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    public function test_a_manager_raises_submits_and_approves_a_change_order(): void
    {
        $id = $this->as($this->manager)
            ->postJson('/api/v1/office/change-orders', $this->payload(['submit' => true]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.laborHours', 16)
            // (1250 + 800) × 1.2
            ->assertJsonPath('data.amount', 2460)
            ->assertJsonPath('data.can.decide', true)
            ->json('data.id');

        $this->as($this->manager)
            ->postJson("/api/v1/office/change-orders/{$id}/approve", ['note' => 'Go ahead'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.billing.state', 'pending');

        $this->as($this->manager)
            ->getJson('/api/v1/office/change-orders?status=approved')
            ->assertOk()
            ->assertJsonCount(1, 'data.orders')
            ->assertJsonPath('data.orders.0.job.name', 'Riverside Medical Center');
    }

    public function test_a_rejection_needs_a_reason(): void
    {
        $id = $this->as($this->manager)->postJson('/api/v1/office/change-orders', $this->payload(['submit' => true]))->json('data.id');

        $this->as($this->manager)->postJson("/api/v1/office/change-orders/{$id}/reject", [])->assertUnprocessable();
        $this->as($this->manager)
            ->postJson("/api/v1/office/change-orders/{$id}/reject", ['note' => 'Wrong wire gauge'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.decisionNote', 'Wrong wire gauge');
    }

    public function test_a_foreman_raises_their_own_but_cannot_decide(): void
    {
        $id = $this->as($this->foremanUser)
            ->postJson('/api/v1/office/change-orders', $this->payload(['submit' => true]))
            ->assertCreated()
            ->assertJsonPath('data.source', 'field')
            ->assertJsonPath('data.can.decide', false)
            ->json('data.id');

        $this->as($this->foremanUser)->postJson("/api/v1/office/change-orders/{$id}/approve")->assertForbidden();
        $this->as($this->foremanUser)
            ->postJson("/api/v1/office/change-orders/{$id}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_evidence_is_attached_and_a_draft_deleted(): void
    {
        Storage::fake('local');
        $id = $this->as($this->manager)->postJson('/api/v1/office/change-orders', $this->payload())->json('data.id');

        $this->as($this->manager)
            ->post("/api/v1/office/change-orders/{$id}/attachments", [
                'attachments' => [UploadedFile::fake()->create('quote.pdf', 12, 'application/pdf')],
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(1, 'data.attachments');

        $this->as($this->manager)->deleteJson("/api/v1/office/change-orders/{$id}")->assertOk();
        $this->assertNull(ChangeOrder::find($id));
    }

    public function test_the_form_options_list_the_jobs_and_reasons(): void
    {
        $this->as($this->manager)
            ->getJson('/api/v1/office/change-orders/options')
            ->assertOk()
            ->assertJsonPath('data.jobs.0.name', 'Riverside Medical Center')
            ->assertJsonPath('data.isManager', true)
            ->assertJsonCount(count(ChangeOrder::REASONS), 'data.reasons');
    }
}
