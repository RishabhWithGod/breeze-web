<?php

namespace Tests\Feature\Api;

use App\Models\CompanyProfile;
use App\Models\Job;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The app's office Job Detail reads what web's `JobShow` does, and its
 * panels write through the same rules.
 */
class MobileOfficeJobTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

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
        $this->job = Job::create([
            'user_id' => $this->manager->id,
            'name' => 'Riverside Medical Center',
            'client' => 'Riverside',
            'status' => 'planning',
        ]);
    }

    private function api(): self
    {
        return $this->withToken($this->manager->createToken('test')->plainTextToken);
    }

    public function test_the_detail_carries_what_web_job_show_does(): void
    {
        $this->api()
            ->getJson("/api/v1/office/jobs/{$this->job->id}")
            ->assertOk()
            ->assertJsonPath('data.job.name', 'Riverside Medical Center')
            ->assertJsonPath('data.job.status', 'planning')
            ->assertJsonPath('data.canEdit', true)
            ->assertJsonPath('data.changeOrders.canRaise', true)
            ->assertJsonStructure(['data' => ['jobCosting', 'fieldMaterials', 'apprenticeAssignments', 'statuses']]);
    }

    public function test_the_status_changes_and_a_completed_job_locks(): void
    {
        $this->api()->postJson("/api/v1/office/jobs/{$this->job->id}/status", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->api()->postJson("/api/v1/office/jobs/{$this->job->id}/status", ['status' => 'planning'])
            ->assertStatus(409);
    }

    public function test_notes_and_documents_are_added_and_removed(): void
    {
        Storage::fake('local');

        $this->api()->postJson("/api/v1/office/jobs/{$this->job->id}/notes", ['body' => 'Panel arrives Tuesday'])
            ->assertCreated();
        $noteId = $this->job->notes()->value('id');

        $this->api()->post("/api/v1/office/jobs/{$this->job->id}/attachments", [
            'file' => UploadedFile::fake()->create('permit.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $attachmentId = $this->job->attachments()->value('id');

        $this->api()->getJson("/api/v1/office/jobs/{$this->job->id}")
            ->assertJsonCount(1, 'data.job.notes')
            ->assertJsonCount(1, 'data.job.attachments');

        $this->api()->deleteJson("/api/v1/office/jobs/{$this->job->id}/notes/{$noteId}")->assertOk();
        $this->api()->deleteJson("/api/v1/office/jobs/{$this->job->id}/attachments/{$attachmentId}")->assertOk();

        $this->assertSame(0, $this->job->notes()->count());
        $this->assertSame(0, $this->job->attachments()->count());
    }

    public function test_a_job_is_deleted(): void
    {
        $this->api()->deleteJson("/api/v1/office/jobs/{$this->job->id}")->assertOk();
        $this->assertSoftDeleted($this->job);
    }

    public function test_a_crew_member_cannot_change_the_job(): void
    {
        $crew = User::factory()->create([
            'role' => 'Journeyman', 'status' => User::STATUS_ACTIVE, 'company_id' => $this->manager->company_id,
        ]);
        $token = $crew->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson("/api/v1/office/jobs/{$this->job->id}/status", ['status' => 'completed'])
            ->assertForbidden();
        $this->withToken($token)->deleteJson("/api/v1/office/jobs/{$this->job->id}")->assertForbidden();
        $this->withToken($token)->postJson("/api/v1/office/jobs/{$this->job->id}/notes", ['body' => 'hello'])
            ->assertForbidden();
        $this->assertSame('planning', $this->job->fresh()->status);
    }

    public function test_someone_elses_job_is_refused(): void
    {
        $stranger = User::factory()->create(['role' => 'Project Manager', 'status' => User::STATUS_ACTIVE]);

        $this->withToken($stranger->createToken('test')->plainTextToken)
            ->getJson("/api/v1/office/jobs/{$this->job->id}")
            ->assertForbidden();
    }
}
