<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TakeoffHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_history_paginates_at_the_configured_page_size(): void
    {
        $this->makeProjects(8);

        $this->actingAs($this->user)
            ->get('/ai-takeoff')
            ->assertInertia(fn (Assert $page) => $page
                ->component('History')
                ->has('projects.data', config('takeoff.per_page'))
                ->where('projects.meta.total', 8)
                ->where('filters.sort', 'date-desc'));
    }

    public function test_history_can_be_searched(): void
    {
        $this->makeProjects(3);

        $this->user->projects()->create([
            'name' => 'Harborview Data Hall',
            'client' => 'Vertex Infrastructure',
            'status' => 'completed',
            'items_count' => 1596,
            'completed_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->get('/ai-takeoff?search=Harborview')
            ->assertInertia(fn (Assert $page) => $page
                ->has('projects.data', 1)
                ->where('projects.data.0.name', 'Harborview Data Hall'));
    }

    public function test_history_can_be_filtered_by_status(): void
    {
        $this->user->projects()->create([
            'name' => 'A draft', 'client' => 'X', 'status' => 'draft', 'items_count' => 1,
        ]);
        $this->user->projects()->create([
            'name' => 'A completed', 'client' => 'X', 'status' => 'completed', 'items_count' => 2,
        ]);

        $this->actingAs($this->user)
            ->get('/ai-takeoff?status=draft')
            ->assertInertia(fn (Assert $page) => $page
                ->has('projects.data', 1)
                ->where('projects.data.0.status', 'draft'));
    }

    public function test_history_can_be_sorted_by_name(): void
    {
        foreach (['Zulu', 'Alpha', 'Mike'] as $name) {
            $this->user->projects()->create([
                'name' => $name, 'client' => 'X', 'status' => 'completed', 'items_count' => 0,
            ]);
        }

        $this->actingAs($this->user)
            ->get('/ai-takeoff?sort=name-asc')
            ->assertInertia(fn (Assert $page) => $page
                ->where('projects.data.0.name', 'Alpha'));
    }

    public function test_an_invalid_filter_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->get('/ai-takeoff?status=not-a-status')
            ->assertSessionHasErrors('status');
    }

    public function test_a_project_can_be_deleted_and_restored(): void
    {
        $project = $this->user->projects()->create([
            'name' => 'Deletable', 'client' => 'X', 'status' => 'completed', 'items_count' => 5,
        ]);

        $this->actingAs($this->user)
            ->from('/ai-takeoff')
            ->delete("/takeoffs/{$project->id}")
            ->assertRedirect('/ai-takeoff');

        $this->assertSoftDeleted($project);

        $this->actingAs($this->user)
            ->from('/ai-takeoff')
            ->post("/takeoffs/{$project->id}/restore")
            ->assertRedirect('/ai-takeoff');

        $this->assertNotSoftDeleted($project->fresh());
    }

    public function test_a_project_belonging_to_someone_else_is_off_limits(): void
    {
        $stranger = User::factory()->create();
        $project = $stranger->projects()->create([
            'name' => 'Theirs', 'client' => 'X', 'status' => 'completed', 'items_count' => 1,
        ]);

        $this->actingAs($this->user)
            ->delete("/takeoffs/{$project->id}")
            ->assertForbidden();

        $this->assertNotSoftDeleted($project);
    }

    public function test_history_only_lists_your_own_projects(): void
    {
        $stranger = User::factory()->create();
        $stranger->projects()->create([
            'name' => 'Theirs', 'client' => 'X', 'status' => 'completed', 'items_count' => 1,
        ]);
        $this->makeProjects(2);

        $this->actingAs($this->user)
            ->get('/ai-takeoff')
            ->assertInertia(fn (Assert $page) => $page->where('projects.meta.total', 2));
    }

    private function makeProjects(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->user->projects()->create([
                'name' => "Project {$i}",
                'client' => 'Some Client',
                'status' => 'completed',
                'items_count' => $i * 10,
                'completed_at' => now()->subDays($i),
            ]);
        }
    }
}
