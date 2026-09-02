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
 * Which of a client's drawings the next takeoff runs against.
 *
 * Before this, a takeoff always used the oldest PDF on record, so a second
 * drawing could be uploaded but never analysed. The rule now: the chosen one,
 * falling back to the first on record whenever nothing is chosen — which is
 * the normal state for a client with a single drawing, and for one whose
 * chosen drawing has since been deleted.
 */
class SelectedDrawingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Storage::fake(config('takeoff.uploads.disk'));

        $this->client = $this->user->projects()->create([
            'name' => 'Harborview Data Hall',
            'client' => 'Harborview Data Hall',
            'status' => 'draft',
        ]);
    }

    public function test_with_nothing_chosen_the_first_drawing_on_record_is_used(): void
    {
        $first = $this->makeDrawing('E-101.pdf');
        $this->makeDrawing('E-102.pdf');

        $this->assertNull($this->client->selected_upload_id);
        $this->assertSame($first->id, $this->client->takeoffDrawing()->id);
    }

    public function test_a_drawing_can_be_chosen_and_the_clients_drawing_name_follows_it(): void
    {
        $this->makeDrawing('E-101.pdf');
        $second = $this->makeDrawing('E-102.pdf');

        $this->actingAs($this->user)
            ->from(route('projects.show', $this->client))
            ->post(route('projects.documents.select', [$this->client, $second]))
            ->assertRedirect(route('projects.show', $this->client))
            ->assertSessionHas('success');

        $this->client->refresh();

        $this->assertSame($second->id, $this->client->selected_upload_id);
        $this->assertSame($second->id, $this->client->takeoffDrawing()->id);
        // The Clients list reads this copy, so it must not name the old one.
        $this->assertSame('E-102.pdf', $this->client->drawing_name);
    }

    public function test_deleting_the_chosen_drawing_falls_back_instead_of_leaving_a_dangling_choice(): void
    {
        $first = $this->makeDrawing('E-101.pdf');
        $second = $this->makeDrawing('E-102.pdf');

        $this->actingAs($this->user)
            ->post(route('projects.documents.select', [$this->client, $second]));

        $this->actingAs($this->user)
            ->delete(route('projects.documents.destroy', [$this->client, $second]))
            ->assertSessionHasNoErrors();

        $this->client->refresh();

        $this->assertNull($this->client->selected_upload_id);
        $this->assertSame($first->id, $this->client->takeoffDrawing()->id);
        $this->assertSame('E-101.pdf', $this->client->drawing_name);
    }

    public function test_a_drawing_belonging_to_another_client_cannot_be_chosen(): void
    {
        $mine = $this->makeDrawing('E-101.pdf');
        $theirs = $this->user->projects()->create([
            'name' => 'Rosewood Clinic', 'client' => 'Rosewood Clinic', 'status' => 'draft',
        ])->uploads()->create([
            'user_id' => $this->user->id,
            'name' => 'R-201.pdf',
            'format' => 'PDF',
            'size_bytes' => 1024,
            'status' => 'completed',
        ]);

        $this->actingAs($this->user)
            ->post(route('projects.documents.select', [$this->client, $theirs]))
            ->assertNotFound();

        $this->assertNull($this->client->refresh()->selected_upload_id);
        $this->assertSame($mine->id, $this->client->takeoffDrawing()->id);
    }

    public function test_someone_elses_client_cannot_have_its_drawing_chosen(): void
    {
        $theirClient = User::factory()->create()->projects()->create([
            'name' => 'Someone Else Tower', 'client' => 'Someone Else Tower', 'status' => 'draft',
        ]);
        $theirDrawing = $theirClient->uploads()->create([
            'user_id' => $theirClient->user_id,
            'name' => 'S-101.pdf',
            'format' => 'PDF',
            'size_bytes' => 1024,
            'status' => 'completed',
        ]);

        $this->actingAs($this->user)
            ->post(route('projects.documents.select', [$theirClient, $theirDrawing]))
            ->assertForbidden();

        $this->assertNull($theirClient->refresh()->selected_upload_id);
    }

    public function test_the_detail_screen_names_the_drawing_that_would_be_used(): void
    {
        $first = $this->makeDrawing('E-101.pdf');
        $second = $this->makeDrawing('E-102.pdf');

        $this->actingAs($this->user)
            ->get(route('projects.show', $this->client))
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents', 2)
                // Nothing chosen yet, so the fallback is what the screen marks.
                ->where('project.selectedUploadId', $first->id));

        $this->actingAs($this->user)
            ->post(route('projects.documents.select', [$this->client, $second]));

        $this->actingAs($this->user)
            ->get(route('projects.show', $this->client))
            ->assertInertia(fn (Assert $page) => $page
                ->where('project.selectedUploadId', $second->id));
    }

    private function makeDrawing(string $name): Upload
    {
        $path = UploadedFile::fake()
            ->create($name, 60, 'application/pdf')
            ->store((string) config('takeoff.uploads.directory'), (string) config('takeoff.uploads.disk'));

        $upload = $this->client->uploads()->create([
            'user_id' => $this->user->id,
            'name' => $name,
            'format' => Upload::formatFor($name),
            'size_bytes' => 60 * 1024,
            'path' => $path,
            'status' => 'completed',
        ]);

        if (blank($this->client->drawing_name)) {
            $this->client->update(['drawing_name' => $name]);
        }

        return $upload;
    }
}
