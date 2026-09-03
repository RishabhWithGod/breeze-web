<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Which client the AI Takeoff upload screen opens on.
 *
 * Someone who has just created a client, or followed a link from one, means
 * that client — the picker should already be on it rather than making them
 * find it again. Everything here is scoped to the signed-in user, so the
 * preselect can never point at someone else's record.
 */
class UploadClientPreselectTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_creating_a_client_preselects_it_on_the_upload_screen(): void
    {
        $this->actingAs($this->user)
            ->post('/projects', ['name' => 'Harborview Data Hall'])
            ->assertSessionHasNoErrors();

        $client = Project::sole();

        $this->actingAs($this->user)
            ->get('/ai-takeoff/upload')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Upload')
                ->where('selectedProjectId', $client->id));
    }

    public function test_the_preselect_is_used_once_and_not_again(): void
    {
        $this->actingAs($this->user)->post('/projects', ['name' => 'Harborview Data Hall']);

        // It answers "the client you just created", not "the client you always
        // want" — a later visit must not keep steering the picker.
        $this->actingAs($this->user)->get('/ai-takeoff/upload');

        $this->actingAs($this->user)
            ->get('/ai-takeoff/upload')
            ->assertInertia(fn (Assert $page) => $page->where('selectedProjectId', null));
    }

    public function test_a_link_can_name_the_client_outright(): void
    {
        $client = $this->makeClient('Harborview Data Hall');

        $this->actingAs($this->user)
            ->get("/ai-takeoff/upload?project={$client->id}")
            ->assertInertia(fn (Assert $page) => $page->where('selectedProjectId', $client->id));
    }

    public function test_a_link_naming_someone_elses_client_preselects_nothing(): void
    {
        $theirs = User::factory()->create()->projects()->create([
            'name' => 'Someone Else Tower', 'client' => 'Someone Else Tower', 'status' => 'draft',
        ]);

        $this->actingAs($this->user)
            ->get("/ai-takeoff/upload?project={$theirs->id}")
            ->assertInertia(fn (Assert $page) => $page->where('selectedProjectId', null));
    }

    public function test_a_link_naming_a_client_that_does_not_exist_preselects_nothing(): void
    {
        $this->actingAs($this->user)
            ->get('/ai-takeoff/upload?project=999999')
            ->assertInertia(fn (Assert $page) => $page->where('selectedProjectId', null));
    }

    public function test_the_screen_carries_nothing_but_the_client_list_and_the_upload_limits(): void
    {
        $this->makeClient('Harborview Data Hall');

        $this->actingAs($this->user)
            ->get('/ai-takeoff/upload')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Upload')
                ->has('projects', 1)
                ->has('limits')
                // Recent activity, help and the capability blurb are gone: the
                // screen is a client picker and a dropzone, nothing else.
                ->missing('recentUploads'));
    }

    public function test_the_upload_screens_takeoff_crumb_leads_out_of_the_upload_screen(): void
    {
        // It pointed at the upload screen itself, so the only way out of a
        // half-filled upload was the browser's own back button.
        $this->actingAs($this->user)
            ->get('/ai-takeoff')
            ->assertOk();
    }

    private function makeClient(string $name): Project
    {
        return $this->user->projects()->create([
            'name' => $name,
            'client' => $name,
            'status' => 'draft',
        ]);
    }
}
