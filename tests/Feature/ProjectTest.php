<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Clients module: a client recorded by hand, and the drawing PDFs it ends
 * up holding.
 *
 * Nothing here uploads a drawing — a PDF only ever arrives through AI Takeoff,
 * against a client that already exists — so the drawing tests below put the
 * `uploads` row on the disk directly, which is what that upload leaves behind.
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

    public function test_the_create_screen_asks_only_for_the_clients_details(): void
    {
        $this->makeProject(['name' => 'Harborview Data Hall']);

        $this->actingAs($this->user)
            ->get('/projects/create')
            ->assertInertia(fn (Assert $page) => $page
                ->component('ProjectCreate')
                // The client's own details, typed. No suggestion list on the
                // name — this screen exists to name a client that is not on
                // one — no drawing picker, and no discipline to choose.
                ->missing('clients')
                ->missing('limits')
                ->missing('disciplines'));
    }

    public function test_a_client_is_created_from_its_details_alone(): void
    {
        $response = $this->actingAs($this->user)->post('/projects', [
            'name' => 'Harborview Data Hall',
            'code' => 'PRJ-2041',
            'location' => '41 Harbor Way',
            'project_type' => 'commercial',
            'notes' => 'Revision C only.',
        ]);

        $project = Project::query()->where('name', 'Harborview Data Hall')->sole();

        $response->assertRedirect(route('projects.show', $project));

        $this->assertSame('draft', $project->status);
        $this->assertSame('commercial', $project->project_type);
        $this->assertSame('PRJ-2041', $project->code);
        // The form no longer asks for a takeoff due date.
        $this->assertNull($project->due_date);
        // The form leaves discipline unset; the column's default stands.
        $this->assertSame('Electrical', $project->discipline);

        // No drawing arrives with it — that comes from AI Takeoff.
        $this->assertSame(0, $project->uploads()->count());
        $this->assertNull($project->drawing_name);

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $project->id,
            'title' => 'Client created',
        ]);
    }

    public function test_a_drawing_posted_to_the_create_form_is_ignored(): void
    {
        // The field is gone from the screen and from the rules, so a file sent
        // by hand is dropped rather than quietly stored.
        $this->actingAs($this->user)
            ->post('/projects', [
                'name' => 'Rosewood Clinic',
                'documents' => [UploadedFile::fake()->create('E-101.pdf', 60, 'application/pdf')],
                'document_titles' => ['Ground floor lighting'],
            ])
            ->assertSessionHasNoErrors();

        $project = Project::query()->sole();

        $this->assertSame(0, $project->uploads()->count());
        $this->assertNull($project->drawing_name);
        $this->assertEmpty(
            Storage::disk(config('takeoff.uploads.disk'))->files(config('takeoff.uploads.directory')),
            'The dropped file must not reach the disk either.',
        );
    }

    public function test_there_is_no_route_for_adding_a_drawing_to_a_client(): void
    {
        $project = $this->makeProject();

        $this->actingAs($this->user)
            ->post("/projects/{$project->id}/documents", [
                'documents' => [UploadedFile::fake()->create('E-101.pdf', 60, 'application/pdf')],
            ])
            ->assertNotFound();

        $this->assertSame(0, $project->uploads()->count());
    }

    public function test_the_client_name_is_required(): void
    {
        // One field, not two: the name *is* the client, and the `client`
        // column is written from it server-side.
        $this->actingAs($this->user)
            ->post('/projects', ['name' => 'ab'])
            ->assertSessionHasErrors('name')
            ->assertSessionDoesntHaveErrors('client');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_removing_a_drawing_deletes_the_file_and_renames_the_clients_drawing(): void
    {
        $project = $this->makeProject();
        $this->makeDrawing($project, 'E-101.pdf');
        $this->makeDrawing($project, 'E-102.pdf');

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
        $document = $this->makeDrawing($project, 'E-101.pdf');

        $this->actingAs($this->user)
            ->get(route('projects.documents.show', [$project, $document]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'inline; filename="E-101.pdf"');
    }

    public function test_the_detail_screen_lists_the_clients_drawings(): void
    {
        $project = $this->makeProject(['name' => 'Harborview Data Hall']);
        $this->makeDrawing($project, 'E-101.pdf', 'Ground floor lighting');

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
                // The screen no longer carries an activity feed, so the trail
                // is not shipped to it either.
                ->missing('activity'));
    }

    public function test_a_project_belonging_to_someone_else_is_out_of_reach(): void
    {
        $other = User::factory()->create()->projects()->create([
            'name' => 'Someone Else Tower', 'client' => 'Other', 'status' => 'draft',
        ]);

        $document = $this->makeDrawing($other, 'E-101.pdf');

        $this->actingAs($this->user)->get(route('projects.show', $other))->assertForbidden();
        $this->actingAs($this->user)->delete(route('projects.destroy', $other))->assertForbidden();
        $this->actingAs($this->user)
            ->delete(route('projects.documents.destroy', [$other, $document]))
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

    /**
     * A drawing on record for a client — what an AI Takeoff upload leaves
     * behind: the PDF on the takeoff disk, and an `uploads` row naming it.
     */
    private function makeDrawing(Project $project, string $name, ?string $title = null): Upload
    {
        $path = UploadedFile::fake()
            ->create($name, 60, 'application/pdf')
            ->store((string) config('takeoff.uploads.directory'), (string) config('takeoff.uploads.disk'));

        $upload = $project->uploads()->create([
            'user_id' => $project->user_id,
            'name' => $name,
            'title' => $title,
            'format' => Upload::formatFor($name),
            'size_bytes' => 60 * 1024,
            'path' => $path,
            'status' => 'completed',
        ]);

        // The first drawing names the client, exactly as the upload does.
        if (blank($project->drawing_name)) {
            $project->update(['drawing_name' => $name]);
        }

        return $upload;
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
