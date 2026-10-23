<?php

namespace Tests\Feature\Api;

use App\Models\CompanyProfile;
use App\Models\Job;
use App\Models\JobFieldMaterial;
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

    public function test_the_list_filters_sorts_and_carries_the_lists_own_figures(): void
    {
        $mk = fn (string $name, string $status, array $x = []) => Job::create([
            'user_id' => $this->manager->id, 'name' => $name, 'client' => 'Riverside', 'status' => $status, ...$x,
        ]);
        $mk('Alpha Wing', 'in-progress', ['job_type' => 'commercial', 'start_date' => '2026-10-02']);
        $mk('Bravo Hall', 'completed', ['job_type' => 'residential', 'start_date' => '2026-10-01']);
        $mk('Charlie Yard', 'planning', ['archived_at' => now()]);

        $names = fn (string $q) => array_column($this->api()->getJson('/api/v1/jobs'.$q)->assertOk()->json('data.jobs'), 'name');

        $this->assertContains('Alpha Wing', $names(''));
        $this->assertSame(['Alpha Wing'], $names('?status=in-progress'));
        $this->assertSame(['Bravo Hall'], $names('?type=residential'));
        $this->assertSame(['Charlie Yard'], $names('?view=archived'));
        $this->assertNotContains('Charlie Yard', $names('?view=active'));
        $this->assertSame(['Bravo Hall'], $names('?search=Bravo'));
        $sorted = $names('?sort=name-asc');
        $this->assertSame(['Alpha Wing', 'Bravo Hall', 'Charlie Yard'], array_slice($sorted, array_search('Alpha Wing', $sorted), 3));

        $row = $this->api()->getJson('/api/v1/jobs?search=Alpha')->json('data.jobs.0');
        foreach (['projectName', 'isArchived', 'isLocked', 'openTasks', 'progress', 'crewCount'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
    }

    public function test_a_job_can_be_archived_unarchived_deleted_and_restored(): void
    {
        $this->api()->postJson("/api/v1/office/jobs/{$this->job->id}/archive")->assertOk();
        $this->assertNotNull($this->job->fresh()->archived_at);
        $this->api()->postJson("/api/v1/office/jobs/{$this->job->id}/unarchive")->assertOk();
        $this->assertNull($this->job->fresh()->archived_at);

        $this->api()->deleteJson("/api/v1/office/jobs/{$this->job->id}")->assertOk();
        $this->assertSoftDeleted('work_jobs', ['id' => $this->job->id]);
        $this->api()->postJson("/api/v1/office/jobs/{$this->job->id}/restore")->assertOk();
        $this->assertNotSoftDeleted('work_jobs', ['id' => $this->job->id]);
    }

    public function test_bulk_sets_a_status_or_deletes_and_leaves_completed_jobs_alone(): void
    {
        $open = Job::create(['user_id' => $this->manager->id, 'name' => 'Open', 'client' => 'R', 'status' => 'planning']);
        $done = Job::create(['user_id' => $this->manager->id, 'name' => 'Done', 'client' => 'R', 'status' => 'completed']);

        $response = $this->api()->postJson('/api/v1/office/jobs/bulk', [
            'ids' => [$open->id, $done->id], 'action' => 'status', 'status' => 'on-hold',
        ])->assertOk();

        $this->assertSame(1, $response->json('data.count'));
        $this->assertSame(1, $response->json('data.skipped'));
        $this->assertSame('on-hold', $open->fresh()->status);
        $this->assertSame('completed', $done->fresh()->status);

        $this->api()->postJson('/api/v1/office/jobs/bulk', ['ids' => [$open->id, $done->id], 'action' => 'delete'])->assertOk();
        $this->assertSoftDeleted('work_jobs', ['id' => $open->id]);
        $this->assertNotSoftDeleted('work_jobs', ['id' => $done->id]);
    }

    private function addedEntry(array $attrs = []): JobFieldMaterial
    {
        return JobFieldMaterial::create([
            'job_id' => $this->job->id, 'kind' => JobFieldMaterial::MATERIAL, 'description' => 'Extra box',
            'unit' => 'ea', 'actual_quantity' => 3, 'unit_price' => 5, 'total' => 15, 'status' => 'pending', ...$attrs,
        ]);
    }

    public function test_the_office_can_edit_and_remove_a_pending_field_entry(): void
    {
        $entry = $this->addedEntry();

        $this->api()->putJson("/api/v1/office/jobs/{$this->job->id}/field-materials/{$entry->id}", [
            'kind' => 'labor', 'description' => 'Extra hours', 'quantity' => 2, 'unit_price' => 80, 'reason' => 'Rework',
        ])->assertOk();

        $fresh = $entry->fresh();
        $this->assertSame('labor', $fresh->kind);
        $this->assertSame('hr', $fresh->unit);
        $this->assertEquals(160, $fresh->total);

        $this->api()->deleteJson("/api/v1/office/jobs/{$this->job->id}/field-materials/{$entry->id}")->assertOk();
        $this->assertDatabaseMissing('job_field_materials', ['id' => $entry->id]);
    }

    public function test_an_approved_field_entry_can_no_longer_be_changed(): void
    {
        $entry = $this->addedEntry(['status' => 'approved']);

        $this->api()->deleteJson("/api/v1/office/jobs/{$this->job->id}/field-materials/{$entry->id}")->assertStatus(409);
    }
}
