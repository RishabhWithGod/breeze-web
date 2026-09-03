<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Each takeoff's own paperwork.
 *
 * Documents were one pile for the whole workspace, which is fine until several
 * takeoffs are running and every one of them shows every other one's files.
 */
class ProjectDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $mine;

    private Project $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create(['role' => 'Project Manager']);
        $client = $this->user->clients()->create(['name' => 'Harborview']);

        $this->mine = $this->user->projects()->create([
            'client_id' => $client->id, 'name' => 'Phase 1',
            'client' => 'Harborview', 'status' => 'draft',
        ]);
        $this->theirs = $this->user->projects()->create([
            'client_id' => $client->id, 'name' => 'Phase 2',
            'client' => 'Harborview', 'status' => 'draft',
        ]);
    }

    public function test_a_takeoff_shows_only_its_own_documents(): void
    {
        $this->documentFor($this->mine, 'Phase 1 contract');
        $this->documentFor($this->theirs, 'Phase 2 contract');

        $this->actingAs($this->user)
            ->get(route('documents.index', ['project' => $this->mine->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Documents')
                ->has('documents.data', 1)
                ->where('documents.data.0.name', 'Phase 1 contract')
                // Named, so the screen says whose paperwork this is and Back
                // returns to the takeoff rather than the workspace.
                ->where('takeoff.id', $this->mine->id)
                ->where('takeoff.name', 'Phase 1'));
    }

    public function test_without_a_takeoff_the_list_is_the_whole_workspace(): void
    {
        $this->documentFor($this->mine, 'Phase 1 contract');
        $this->documentFor($this->theirs, 'Phase 2 contract');

        $this->actingAs($this->user)
            ->get(route('documents.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents.data', 2)
                ->where('takeoff', null));
    }

    public function test_uploading_from_a_takeoff_files_it_under_that_takeoff(): void
    {
        $this->actingAs($this->user)
            ->post(route('documents.store'), [
                'file' => UploadedFile::fake()->create('contract.pdf', 20, 'application/pdf'),
                'name' => 'Signed contract',
                'document_type' => Document::TYPES[0],
                'project_id' => $this->mine->id,
                'visibility' => 'team',
            ])
            // And lands back in that takeoff's list, not the workspace's.
            ->assertRedirect(route('documents.index', ['project' => $this->mine->id]));

        $this->assertSame($this->mine->id, Document::sole()->project_id);
    }

    public function test_the_upload_form_carries_the_takeoff_it_was_opened_from(): void
    {
        $this->actingAs($this->user)
            ->get(route('documents.create', ['project' => $this->mine->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('DocumentUpload')
                ->where('projectId', $this->mine->id));
    }

    private function documentFor(Project $project, string $name): Document
    {
        return Document::create([
            'name' => $name,
            'original_filename' => "{$name}.pdf",
            'storage_path' => "documents/{$name}.pdf",
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size' => 1024,
            'document_type' => Document::TYPES[0],
            'project_id' => $project->id,
            'uploaded_by' => $this->user->id,
            'version' => 1,
            'is_latest' => true,
            'visibility' => 'team',
        ]);
    }
}
