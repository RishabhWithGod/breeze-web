<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Projects module: a project and its drawing PDFs, defined by hand rather
 * than inferred from an upload.
 */
class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Storage::fake(config('takeoff.uploads.disk'));
    }

    public function test_the_list_shows_only_the_signed_in_users_projects(): void
    {
        $this->makeProject(['name' => 'Harborview Data Hall']);
        User::factory()->create()->projects()->create([
            'name' => 'Someone Else Tower', 'client' => 'Other', 'status' => 'draft',
        ]);

        $this->actingAs($this->user)
            ->get('/projects')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Projects')
                ->has('projects.data', 1)
                ->where('projects.data.0.name', 'Harborview Data Hall')
                ->where('filters.sort', 'recent')
                ->where('counts.total', 1));
    }

    public function test_the_list_can_be_searched_by_project_number(): void
    {
        $this->makeProject(['name' => 'Harborview Data Hall', 'code' => 'PRJ-2041']);
        $this->makeProject(['name' => 'Rosewood Clinic', 'code' => 'PRJ-9000']);

        $this->actingAs($this->user)
            ->get('/projects?search=PRJ-2041')
            ->assertInertia(fn (Assert $page) => $page
                ->has('projects.data', 1)
                ->where('projects.data.0.name', 'Harborview Data Hall'));
    }

    public function test_the_list_can_be_filtered_by_status(): void
    {
        $this->makeProject(['name' => 'A draft', 'status' => 'draft']);
        $this->makeProject(['name' => 'A completed', 'status' => 'completed']);

        $this->actingAs($this->user)
            ->get('/projects?status=completed')
            ->assertInertia(fn (Assert $page) => $page
                ->has('projects.data', 1)
                ->where('projects.data.0.status', 'completed'));
    }

    public function test_the_create_screen_advertises_the_limits_it_enforces(): void
    {
        $this->actingAs($this->user)
            ->get('/projects/create')
            ->assertInertia(fn (Assert $page) => $page
                ->component('ProjectCreate')
                ->where('limits.maxFiles', config('takeoff.uploads.max_files'))
                ->has('disciplines'));
    }

    public function test_a_project_is_created_with_its_labelled_pdf(): void
    {
        // One PDF per request — `takeoff.uploads.max_files` is 1, so a project's
        // drawing set is built by adding one PDF at a time, not in a batch.
        $response = $this->actingAs($this->user)->post('/projects', [
            'name' => 'Harborview Data Hall',
            'code' => 'PRJ-2041',
            'client' => 'Vertex Infrastructure',
            'location' => '41 Harbor Way',
            'discipline' => 'Electrical',
            'project_type' => 'commercial',
            'due_date' => '2026-09-01',
            'notes' => 'Revision C only.',
            'documents' => [UploadedFile::fake()->create('E-101.pdf', 120, 'application/pdf')],
            'document_titles' => ['Ground floor lighting'],
        ]);

        $project = Project::query()->where('name', 'Harborview Data Hall')->sole();

        $response->assertRedirect(route('projects.show', $project));

        $this->assertSame('draft', $project->status);
        $this->assertSame('commercial', $project->project_type);
        $this->assertSame('PRJ-2041', $project->code);
        $this->assertSame('2026-09-01', $project->due_date->toDateString());
        // The drawing a takeoff would run against, mirrored onto the project.
        $this->assertSame('E-101.pdf', $project->drawing_name);

        $document = $project->uploads()->sole();
        $this->assertSame('Ground floor lighting', $document->title);

        $disk = Storage::disk(config('takeoff.uploads.disk'));
        $this->assertTrue($disk->exists($document->path));

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $project->id,
            'title' => 'Project created',
        ]);
    }

    public function test_only_one_pdf_can_be_submitted_at_a_time(): void
    {
        $this->actingAs($this->user)
            ->post('/projects', [
                'name' => 'Harborview Data Hall',
                'client' => 'Vertex Infrastructure',
                'documents' => [
                    UploadedFile::fake()->create('E-101.pdf', 60, 'application/pdf'),
                    UploadedFile::fake()->create('E-102.pdf', 60, 'application/pdf'),
                ],
            ])
            ->assertSessionHasErrors('documents');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_a_second_pdf_is_added_to_a_project_in_its_own_request(): void
    {
        $project = $this->makeProject(['name' => 'Harborview Data Hall']);

        $this->actingAs($this->user)->post(route('projects.documents.store', $project), [
            'documents' => [UploadedFile::fake()->create('E-101.pdf', 120, 'application/pdf')],
            'document_titles' => ['Ground floor lighting'],
        ]);

        $this->actingAs($this->user)->post(route('projects.documents.store', $project), [
            'documents' => [UploadedFile::fake()->create('E-102.pdf', 90, 'application/pdf')],
        ]);

        $documents = $project->uploads()->oldest()->get();
        $this->assertCount(2, $documents);
        $this->assertSame('Ground floor lighting', $documents[0]->title);
        // No label given, so the file names itself.
        $this->assertNull($documents[1]->title);
        $this->assertSame('E-102.pdf', $documents[1]->label());
    }

    public function test_a_project_can_be_created_before_any_drawing_exists(): void
    {
        $this->actingAs($this->user)
            ->post('/projects', ['name' => 'Rosewood Clinic', 'client' => 'Rosewood Health'])
            ->assertSessionHasNoErrors();

        $project = Project::query()->sole();

        $this->assertSame(0, $project->uploads()->count());
        $this->assertNull($project->drawing_name);
        // The form leaves discipline unset; the module's default stands.
        $this->assertSame('Electrical', $project->discipline);
    }

    public function test_the_name_and_the_client_are_required(): void
    {
        $this->actingAs($this->user)
            ->post('/projects', ['name' => 'ab'])
            ->assertSessionHasErrors(['name', 'client']);

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_only_pdfs_are_accepted_as_project_drawings(): void
    {
        $this->actingAs($this->user)
            ->post('/projects', [
                'name' => 'Rosewood Clinic',
                'client' => 'Rosewood Health',
                'documents' => [UploadedFile::fake()->create('plan.dwg', 40)],
            ])
            ->assertSessionHasErrors('documents.0');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_drawings_can_be_added_to_an_existing_project(): void
    {
        $project = $this->makeProject();

        $this->actingAs($this->user)
            ->post(route('projects.documents.store', $project), [
                'documents' => [UploadedFile::fake()->create('E-201.pdf', 60, 'application/pdf')],
                'document_titles' => ['Panel schedules'],
            ])
            ->assertSessionHasNoErrors();

        $document = $project->uploads()->sole();
        $this->assertSame('Panel schedules', $document->title);
        // The project had no drawing on record, so its first one names it.
        $this->assertSame('E-201.pdf', $project->refresh()->drawing_name);
    }

    public function test_removing_a_drawing_deletes_the_file_and_renames_the_project_drawing(): void
    {
        $project = $this->makeProject();

        $this->actingAs($this->user)->post(route('projects.documents.store', $project), [
            'documents' => [UploadedFile::fake()->create('E-101.pdf', 60, 'application/pdf')],
        ]);
        $this->actingAs($this->user)->post(route('projects.documents.store', $project), [
            'documents' => [UploadedFile::fake()->create('E-102.pdf', 60, 'application/pdf')],
        ]);

        $documents = $project->uploads()->oldest()->get();
        $path = $documents[0]->path;

        $this->actingAs($this->user)
            ->delete(route('projects.documents.destroy', [$project, $documents[0]]))
            ->assertSessionHasNoErrors();

        $this->assertFalse(Storage::disk(config('takeoff.uploads.disk'))->exists($path));
        $this->assertDatabaseMissing('uploads', ['id' => $documents[0]->id]);
        // The remaining drawing takes over as the one the project is named after.
        $this->assertSame('E-102.pdf', $project->refresh()->drawing_name);
    }

    public function test_a_drawing_is_streamed_inline(): void
    {
        $project = $this->makeProject();

        $this->actingAs($this->user)->post(route('projects.documents.store', $project), [
            'documents' => [UploadedFile::fake()->create('E-101.pdf', 60, 'application/pdf')],
        ]);

        $document = $project->uploads()->sole();

        $this->actingAs($this->user)
            ->get(route('projects.documents.show', [$project, $document]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'inline; filename="E-101.pdf"');
    }

    public function test_the_detail_screen_lists_the_projects_drawings(): void
    {
        $project = $this->makeProject(['name' => 'Harborview Data Hall']);

        $this->actingAs($this->user)->post(route('projects.documents.store', $project), [
            'documents' => [UploadedFile::fake()->create('E-101.pdf', 60, 'application/pdf')],
            'document_titles' => ['Ground floor lighting'],
        ]);

        $this->actingAs($this->user)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->component('ProjectShow')
                ->where('project.name', 'Harborview Data Hall')
                ->has('documents', 1)
                ->where('documents.0.label', 'Ground floor lighting')
                ->where('documents.0.available', true)
                // Nothing has been analysed, so there is no takeoff to hand off to.
                ->where('project.takeoffUrl', null)
                ->has('activity'));
    }

    public function test_a_project_belonging_to_someone_else_is_out_of_reach(): void
    {
        $other = User::factory()->create()->projects()->create([
            'name' => 'Someone Else Tower', 'client' => 'Other', 'status' => 'draft',
        ]);

        $this->actingAs($this->user)->get(route('projects.show', $other))->assertForbidden();
        $this->actingAs($this->user)->delete(route('projects.destroy', $other))->assertForbidden();
        $this->actingAs($this->user)
            ->post(route('projects.documents.store', $other), [
                'documents' => [UploadedFile::fake()->create('E-101.pdf', 10, 'application/pdf')],
            ])
            ->assertForbidden();
    }

    public function test_deleting_a_project_is_a_soft_delete(): void
    {
        $project = $this->makeProject();

        $this->actingAs($this->user)
            ->delete(route('projects.destroy', $project))
            ->assertRedirect(route('projects.index'));

        $this->assertSoftDeleted('projects', ['id' => $project->id]);
    }

    /** @param array<string, mixed> $attributes */
    private function makeProject(array $attributes = []): Project
    {
        return $this->user->projects()->create([
            'name' => 'Harborview Data Hall',
            'client' => 'Vertex Infrastructure',
            'status' => 'draft',
            'review_status' => 'none',
            ...$attributes,
        ]);
    }
}
