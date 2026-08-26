<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\Upload;
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

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->manager = User::factory()->create(['role' => 'Project Manager']);
        $this->electrician = User::factory()->create(['role' => 'Electrician']);
    }

    public function test_the_upload_screen_is_a_real_page_not_a_popup(): void
    {
        $job = $this->makeJob();

        $this->actingAs($this->manager)
            ->get("/documents/create?job_id={$job->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DocumentUpload')
                ->where('jobId', $job->id)
                ->has('jobs')
                ->has('importableUploads'));
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

    public function test_the_list_defaults_to_one_row_per_family_and_all_shows_every_version(): void
    {
        $document = $this->makeDocument($this->manager);

        $this->actingAs($this->manager)->post("/documents/{$document->id}/versions", [
            'file' => UploadedFile::fake()->create('plans-v2.pdf', 400, 'application/pdf'),
        ]);

        $this->actingAs($this->manager)
            ->get('/documents')
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 1)
                ->where('documents.data.0.version', 2));

        $this->actingAs($this->manager)
            ->get('/documents?version_status=all')
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 2));
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

    public function test_favoriting_persists_and_shows_under_the_favorites_tab(): void
    {
        $document = $this->makeDocument($this->manager);

        $this->actingAs($this->manager)->post("/documents/{$document->id}/favorite")->assertRedirect();
        $this->assertTrue($document->isFavoritedBy($this->manager));

        $this->actingAs($this->manager)
            ->get('/documents?tab=favorites')
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 1));

        // Unfavorite removes it again.
        $this->actingAs($this->manager)->post("/documents/{$document->id}/favorite");
        $this->assertFalse($document->isFavoritedBy($this->manager->refresh()));
    }

    public function test_archiving_moves_a_document_out_of_all_documents_and_into_archived(): void
    {
        $document = $this->makeDocument($this->manager);

        $this->actingAs($this->manager)->post("/documents/{$document->id}/archive")->assertRedirect();

        $this->actingAs($this->manager)
            ->get('/documents')
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));

        $this->actingAs($this->manager)
            ->get('/documents?tab=archived')
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 1));

        $this->actingAs($this->manager)->post("/documents/{$document->id}/restore");
        $this->assertFalse($document->refresh()->is_archived);
    }

    public function test_sharing_a_private_document_notifies_the_recipient_and_lists_it_under_shared_with_me(): void
    {
        // Private, not team-visible — the only way the electrician can see this
        // at all is through the share, which is exactly what this test locks in.
        $document = $this->makeDocument($this->manager, ['visibility' => Document::VISIBILITY_PRIVATE]);

        $this->actingAs($this->manager)->post("/documents/{$document->id}/share", [
            'user_id' => $this->electrician->id,
            'permission' => 'view',
        ])->assertRedirect();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->electrician->id,
            'type' => 'document-shared',
        ]);

        // Not visible in the main list before being shared with — a plain private doc.
        $other = User::factory()->create(['role' => 'Electrician']);
        $this->actingAs($other)
            ->get('/documents')
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));

        $this->actingAs($this->electrician)
            ->get('/documents?tab=shared')
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 1));
    }

    public function test_a_non_owner_non_manager_cannot_delete_someone_elses_document(): void
    {
        $document = $this->makeDocument($this->manager);

        $this->actingAs($this->electrician)->delete("/documents/{$document->id}")->assertForbidden();
    }

    public function test_filtering_by_job_only_returns_documents_for_that_job(): void
    {
        $jobA = $this->makeJob(['name' => 'Job A']);
        $jobB = $this->makeJob(['name' => 'Job B']);

        $this->makeDocument($this->manager, ['job_id' => $jobA->id]);
        $this->makeDocument($this->manager, ['job_id' => $jobB->id]);

        $this->actingAs($this->manager)
            ->get("/documents?job_id={$jobA->id}")
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 1)
                ->where('documents.data.0.jobId', $jobA->id));
    }

    public function test_a_private_document_is_hidden_from_other_non_manager_users(): void
    {
        $other = User::factory()->create(['role' => 'Electrician']);
        $document = $this->makeDocument($other, ['visibility' => Document::VISIBILITY_PRIVATE]);

        $this->actingAs($this->electrician)
            ->get('/documents')
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));

        // A manager can still see it.
        $this->actingAs($this->manager)
            ->get('/documents')
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 1));
    }

    public function test_importing_an_ai_takeoff_drawing_reuses_the_same_file_without_a_second_upload(): void
    {
        Storage::disk('local')->put('uploads/riverside-plans.pdf', 'pdf-bytes');
        $upload = Upload::create([
            'user_id' => $this->manager->id,
            'name' => 'Riverside Plans.pdf',
            'format' => 'PDF',
            'size_bytes' => 9,
            'path' => 'uploads/riverside-plans.pdf',
            'status' => 'complete',
        ]);

        $response = $this->actingAs($this->manager)->post('/documents/import-upload', [
            'upload_id' => $upload->id,
            'document_type' => 'Electrical Drawing',
            'visibility' => 'team',
        ]);

        $response->assertRedirect();

        $document = Document::first();
        $this->assertNotNull($document);
        $this->assertSame($upload->id, $document->upload_id);
        $this->assertSame($upload->path, $document->storage_path);

        // No second file was written into the documents directory — it points at the exact same bytes.
        $this->assertSame([], Storage::disk('local')->allFiles('documents'));
        Storage::disk('local')->assertExists($upload->path);
    }

    /** A takeoff drawing belongs to whoever ran it — importing someone else's by id is not just a UI omission. */
    public function test_a_user_cannot_import_someone_elses_ai_takeoff_upload(): void
    {
        Storage::disk('local')->put('uploads/someone-elses-plans.pdf', 'pdf-bytes');
        $upload = Upload::create([
            'user_id' => $this->manager->id,
            'name' => 'Someone Elses Plans.pdf',
            'format' => 'PDF',
            'size_bytes' => 9,
            'path' => 'uploads/someone-elses-plans.pdf',
            'status' => 'complete',
        ]);

        $this->actingAs($this->electrician)->post('/documents/import-upload', [
            'upload_id' => $upload->id,
            'document_type' => 'Electrical Drawing',
            'visibility' => 'team',
        ])->assertForbidden();

        $this->assertSame(0, Document::count());
    }

    /** The import picker itself must never list another user's upload as an option. */
    public function test_the_importable_uploads_list_only_shows_this_users_own_uploads(): void
    {
        Storage::disk('local')->put('uploads/managers-plans.pdf', 'pdf-bytes');
        Upload::create([
            'user_id' => $this->manager->id,
            'name' => 'Managers Plans.pdf',
            'format' => 'PDF',
            'size_bytes' => 9,
            'path' => 'uploads/managers-plans.pdf',
            'status' => 'complete',
        ]);

        $this->actingAs($this->electrician)
            ->get('/documents/create')
            ->assertInertia(fn (Assert $page) => $page->has('importableUploads', 0));
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
            'visibility' => Document::VISIBILITY_TEAM,
            'version' => 1,
            'is_latest' => true,
            ...$attributes,
        ]);
    }
}
