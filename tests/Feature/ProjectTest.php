<?php

namespace Tests\Feature;

use App\Models\Client;
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

    public function test_the_list_shows_only_the_signed_in_users_projects_under_their_clients(): void
    {
        $this->makeProject(['name' => 'Harborview Data Hall']);
        User::factory()->create()->projects()->create([
            'name' => 'Someone Else Tower', 'client' => 'Other', 'status' => 'draft',
        ]);

        $this->actingAs($this->user)
            ->get('/projects')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Projects')
                ->has('clients.data', 1)
                ->where('clients.data.0.name', 'Vertex Infrastructure')
                ->has('clients.data.0.projects', 1)
                ->where('clients.data.0.projects.0.name', 'Harborview Data Hall')
                ->where('filters.status', 'all')
                ->where('totalProjects', 1));
    }

    public function test_the_list_can_be_searched_by_project_number(): void
    {
        $this->makeProject(['name' => 'Harborview Data Hall', 'code' => 'PRJ-2041']);
        $this->makeProject(['name' => 'Rosewood Clinic', 'code' => 'PRJ-9000']);

        $this->actingAs($this->user)
            ->get('/projects?search=PRJ-2041')
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients.data.0.projects', 1)
                ->where('clients.data.0.projects.0.name', 'Harborview Data Hall')
                ->where('totalProjects', 1));
    }

    public function test_the_list_can_be_filtered_by_status(): void
    {
        $this->makeProject(['name' => 'A draft', 'status' => 'draft']);
        $this->makeProject(['name' => 'A completed', 'status' => 'completed']);

        $this->actingAs($this->user)
            ->get('/projects?status=completed')
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients.data.0.projects', 1)
                ->where('clients.data.0.projects.0.status', 'completed'));
    }

    public function test_the_create_screen_asks_for_a_client_and_the_projects_own_details(): void
    {
        $this->makeProject(['name' => 'Harborview Data Hall']);

        $this->actingAs($this->user)
            ->get('/projects/create')
            ->assertInertia(fn (Assert $page) => $page
                ->component('ProjectCreate')
                // A project is always for a client, picked from the address book.
                // No drawing picker, and no discipline to choose.
                ->has('clients', 1)
                ->missing('limits')
                ->missing('disciplines'));
    }

    public function test_a_project_is_created_under_a_client_from_its_details_alone(): void
    {
        $client = $this->makeClient();
        $client->addresses()->create(['label' => 'Main', 'address' => '41 Harbor Way', 'is_primary' => true]);

        $response = $this->actingAs($this->user)->post('/projects', [
            'client_id' => $client->id,
            'name' => 'Harborview Data Hall',
            'project_type' => 'commercial',
        ]);

        $project = Project::query()->where('name', 'Harborview Data Hall')->sole();

        // Straight on to the drawing upload, with this project already picked.
        $response->assertRedirect(route('uploads.create', ['project' => $project->id]));

        $this->assertSame('draft', $project->status);
        $this->assertSame('commercial', $project->project_type);
        $this->assertSame($client->id, $project->client_id);
        $this->assertSame('Vertex Infrastructure', $project->client);
        // The place comes from the client's primary site, not from the form.
        $this->assertSame('41 Harbor Way', $project->location);
        $this->assertNull($project->due_date);
        // The form leaves discipline unset; the column's default stands.
        $this->assertSame('Electrical', $project->discipline);

        // No drawing arrives with it — that comes from AI Takeoff.
        $this->assertSame(0, $project->uploads()->count());
        $this->assertNull($project->drawing_name);

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $project->id,
            'title' => 'Project opened',
        ]);
    }

    public function test_a_project_must_be_for_a_client(): void
    {
        $this->actingAs($this->user)
            ->post('/projects', ['name' => 'Harborview Data Hall'])
            ->assertSessionHasErrors('client_id');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_a_drawing_posted_to_the_create_form_is_ignored(): void
    {
        // The field is gone from the screen and from the rules, so a file sent
        // by hand is dropped rather than quietly stored.
        $this->actingAs($this->user)
            ->post('/projects', [
                'client_id' => $this->makeClient()->id,
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

    public function test_the_project_name_is_required(): void
    {
        // The `client` column is written from the picked client server-side.
        $this->actingAs($this->user)
            ->post('/projects', ['client_id' => $this->makeClient()->id, 'name' => 'ab'])
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

    private function makeClient(string $name = 'Vertex Infrastructure'): Client
    {
        return $this->user->clients()->create(['name' => $name]);
    }

    /** @param array<string, mixed> $attributes */
    private function makeProject(array $attributes = []): Project
    {
        return $this->user->projects()->create([
            'name' => 'Harborview Data Hall',
            'client_id' => $this->user->clients()->firstOrCreate(['name' => 'Vertex Infrastructure'])->id,
            'client' => 'Vertex Infrastructure',
            'status' => 'draft',
            'review_status' => 'none',
            ...$attributes,
        ]);
    }
}
