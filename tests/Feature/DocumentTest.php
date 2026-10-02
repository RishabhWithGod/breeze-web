<?php

namespace Tests\Feature;

use App\Models\CompanyProfile;
use App\Models\Document;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Documents: upload, versioning, favorites, sharing, archiving and the
 * permissions gating who may edit or delete someone else's file.
 */
class DocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $electrician;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->manager = User::factory()->create(['role' => 'Project Manager']);
        $this->electrician = User::factory()->create(['role' => 'Electrician']);

        // Documents are filed against a takeoff, and the list and the policy both go by its owner.
        $this->project = $this->makeProject($this->manager);
    }

    public function test_the_upload_screen_is_a_real_page_not_a_popup(): void
    {
        $job = $this->makeJob();

        $this->actingAs($this->manager)
            ->get("/documents/create?job_id={$job->id}&project={$this->project->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DocumentUpload')
                ->where('jobId', $job->id)
                ->where('projectId', $this->project->id)
                ->has('maxFileSizeMb'));
    }

    public function test_uploading_a_document_stores_the_file_and_redirects_to_the_list(): void
    {
        $job = $this->makeJob();

        $response = $this->actingAs($this->manager)->post('/documents', [
            'file' => UploadedFile::fake()->create('plans.pdf', 500, 'application/pdf'),
            'name' => 'Riverside Complex - Electrical Plans',
            'document_type' => 'Blueprint',
            'job_id' => $job->id,
            'visibility' => 'team',
        ]);

        // No takeoff named, so back to the general list.
        $response->assertRedirect('/documents');

        $document = Document::first();
        $this->assertNotNull($document);
        $this->assertSame('Riverside Complex - Electrical Plans', $document->name);
        $this->assertSame(1, $document->version);
        $this->assertTrue($document->is_latest);
        Storage::disk('local')->assertExists($document->storage_path);
    }

    public function test_uploading_a_new_version_preserves_the_old_file_and_promotes_the_new_one(): void
    {
        $document = $this->makeDocument($this->manager);
        $originalPath = $document->storage_path;

        $this->actingAs($this->manager)->post("/documents/{$document->id}/versions", [
            'file' => UploadedFile::fake()->create('plans-v2.pdf', 400, 'application/pdf'),
        ])->assertRedirect();

        $document->refresh();
        $this->assertFalse($document->is_latest);

        $latest = Document::where('is_latest', true)->first();
        $this->assertSame(2, $latest->version);
        $this->assertSame($document->familyRootId(), $latest->version_root_id);

        // The v1 bytes are untouched — a new version never overwrites the old file.
        Storage::disk('local')->assertExists($originalPath);
        Storage::disk('local')->assertExists($latest->storage_path);
        $this->assertNotSame($originalPath, $latest->storage_path);
    }

    public function test_the_list_shows_only_the_latest_version_of_each_family(): void
    {
        $document = $this->makeDocument($this->manager);

        $this->actingAs($this->manager)->post("/documents/{$document->id}/versions", [
            'file' => UploadedFile::fake()->create('plans-v2.pdf', 400, 'application/pdf'),
        ]);

        // Earlier versions are opened through History, never listed. There is no filter to turn that off.
        foreach (['/documents', '/documents?version_status=all'] as $url) {
            $this->actingAs($this->manager)
                ->get($url)
                ->assertInertia(fn (Assert $page) => $page->has('documents.data', 1)
                    ->where('documents.data.0.version', 2));
        }

        $this->actingAs($this->manager)
            ->getJson("/documents/{$document->id}/history")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_deleting_the_latest_version_promotes_the_previous_one(): void
    {
        $document = $this->makeDocument($this->manager);

        $this->actingAs($this->manager)->post("/documents/{$document->id}/versions", [
            'file' => UploadedFile::fake()->create('plans-v2.pdf', 400, 'application/pdf'),
        ]);

        $v2 = Document::where('is_latest', true)->first();

        $this->actingAs($this->manager)->delete("/documents/{$v2->id}")->assertRedirect();

        $document->refresh();
        $this->assertTrue($document->is_latest);
    }

    public function test_favoriting_persists_and_is_flagged_in_the_list(): void
    {
        $document = $this->makeDocument($this->manager);

        $this->actingAs($this->manager)->post("/documents/{$document->id}/favorite")->assertRedirect();
        $this->assertTrue($document->isFavoritedBy($this->manager));

        $this->actingAs($this->manager)
            ->get('/documents')
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 1)
                ->where('documents.data.0.isFavorite', true));

        // Unfavorite removes it again.
        $this->actingAs($this->manager)->post("/documents/{$document->id}/favorite");
        $this->assertFalse($document->isFavoritedBy($this->manager->refresh()));
    }

    public function test_archiving_marks_a_document_in_the_list_and_restoring_clears_it(): void
    {
        $document = $this->makeDocument($this->manager);

        $this->actingAs($this->manager)->post("/documents/{$document->id}/archive")->assertRedirect();
        $this->assertTrue($document->refresh()->is_archived);

        // Archived documents stay in the one list, marked, so there is something to restore them from.
        $this->actingAs($this->manager)
            ->get('/documents')
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 1)
                ->where('documents.data.0.isArchived', true));

        $this->actingAs($this->manager)->post("/documents/{$document->id}/restore");
        $this->assertFalse($document->refresh()->is_archived);

        $this->actingAs($this->manager)
            ->get('/documents')
            ->assertInertia(fn (Assert $page) => $page->where('documents.data.0.isArchived', false));
    }

    public function test_sharing_a_private_document_notifies_the_recipient_and_lets_them_open_it(): void
    {
        // Private, not team-visible — the only way the electrician can open this
        // at all is through the share, which is exactly what this test locks in.
        $document = $this->makeDocument($this->manager, ['visibility' => Document::VISIBILITY_PRIVATE]);

        $this->actingAs($this->electrician)->get("/documents/{$document->id}/download")->assertForbidden();

        $this->actingAs($this->manager)->post("/documents/{$document->id}/share", [
            'user_id' => $this->electrician->id,
            'permission' => 'view',
        ])->assertRedirect();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->electrician->id,
            'type' => 'document-shared',
        ]);

        $this->actingAs($this->electrician)->get("/documents/{$document->id}/download")->assertOk();
    }

    public function test_a_non_owner_non_manager_cannot_delete_someone_elses_document(): void
    {
        $document = $this->makeDocument($this->manager);

        $this->actingAs($this->electrician)->delete("/documents/{$document->id}")->assertForbidden();
    }

    public function test_the_list_is_one_takeoffs_paperwork_when_a_project_is_named(): void
    {
        $other = $this->makeProject($this->manager, ['name' => 'Other Takeoff']);

        $this->makeDocument($this->manager);
        $this->makeDocument($this->manager, ['project_id' => $other->id]);

        $this->actingAs($this->manager)
            ->get("/documents?project={$other->id}")
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 1)
                ->where('documents.data.0.projectId', $other->id)
                ->where('takeoff.id', $other->id));
    }

    public function test_another_managers_project_cannot_be_named_in_the_list(): void
    {
        $theirs = $this->makeProject($this->electrician);
        $this->makeDocument($this->electrician, ['project_id' => $theirs->id]);

        $this->actingAs($this->manager)
            ->get("/documents?project={$theirs->id}")
            ->assertSessionHasErrors('project');
    }

    public function test_a_private_document_is_hidden_from_other_non_manager_users(): void
    {
        // Two people on one company's books: the manager's takeoff is the electrician's to work on too.
        $company = CompanyProfile::create([
            'user_id' => $this->manager->id, 'name' => 'Acme Electric', 'business_address' => '1 Main St',
            'primary_contact' => 'A', 'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        $this->manager->forceFill(['company_id' => $company->id])->save();
        $this->electrician->forceFill(['company_id' => $company->id])->save();

        $colleague = User::factory()->create(['role' => 'Electrician']);
        $colleague->forceFill(['company_id' => $company->id])->save();
        $this->makeDocument($colleague, ['visibility' => Document::VISIBILITY_PRIVATE]);

        $this->actingAs($this->electrician)
            ->get('/documents')
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));

        // A manager can still see it.
        $this->actingAs($this->manager)
            ->get('/documents')
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 1));
    }

    private function makeProject(User $owner, array $attributes = []): Project
    {
        return $owner->projects()->create([
            'name' => 'Riverside Complex',
            'client' => 'Riverside Properties LLC',
            'status' => 'draft',
            'review_status' => 'none',
            ...$attributes,
        ]);
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

    private function makeDocument(User $user, array $attributes = []): Document
    {
        $path = UploadedFile::fake()->create('plans.pdf', 500, 'application/pdf')
            ->store('documents', 'local');

        return Document::create([
            'name' => 'Electrical Plans',
            'original_filename' => 'plans.pdf',
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size' => 512000,
            'document_type' => 'Blueprint',
            'uploaded_by' => $user->id,
            'project_id' => $this->project->id,
            'visibility' => Document::VISIBILITY_TEAM,
            'version' => 1,
            'is_latest' => true,
            ...$attributes,
        ]);
    }
}
