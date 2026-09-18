<?php

namespace Tests\Feature;

use App\Models\Estimate;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * An addendum is never its own row on the Estimates list — it belongs to,
 * and is only ever read from, the original estimate it adds scope to.
 */
class EstimateListExcludesAddendaTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_addendum_does_not_appear_as_its_own_row_on_the_estimates_list(): void
    {
        $user = User::factory()->create(['role' => 'Project Manager']);
        $project = Project::create([
            'user_id' => $user->id,
            'name' => 'Harborview Data Hall',
            'client' => 'Harborview Data Hall',
            'status' => 'draft',
        ]);

        $original = Estimate::create([
            'project_id' => $project->id,
            'number' => 'EST-1001',
            'client' => 'Harborview Data Hall',
            'project' => 'Harborview Data Hall',
            'issued_on' => now()->toDateString(),
            'status' => 'draft',
            'kind' => Estimate::KIND_STANDALONE,
            'amount' => 500,
        ]);

        Estimate::create([
            'project_id' => $project->id,
            'parent_estimate_id' => $original->id,
            'addendum_number' => 1,
            'number' => 'EST-1002',
            'client' => 'Harborview Data Hall',
            'project' => 'Harborview Data Hall',
            'issued_on' => now()->toDateString(),
            'status' => 'draft',
            'kind' => Estimate::KIND_ADDENDUM,
            'amount' => 200,
        ]);

        $this->actingAs($user)
            ->get('/estimates')
            ->assertInertia(fn (Assert $page) => $page
                ->has('estimates.data', 1)
                ->where('estimates.data.0.id', $original->id));
    }

    /** A merged (job-raised) estimate is unaffected — only addenda are excluded. */
    public function test_a_merged_estimate_still_appears_on_the_list(): void
    {
        $user = User::factory()->create(['role' => 'Project Manager']);
        $project = Project::create([
            'user_id' => $user->id,
            'name' => 'Harborview Data Hall',
            'client' => 'Harborview Data Hall',
            'status' => 'draft',
        ]);

        $merged = Estimate::create([
            'project_id' => $project->id,
            'number' => 'EST-2001',
            'client' => 'Harborview Data Hall',
            'project' => 'Harborview Data Hall',
            'issued_on' => now()->toDateString(),
            'status' => 'approved',
            'kind' => Estimate::KIND_MERGED,
            'amount' => 800,
        ]);

        $this->actingAs($user)
            ->get('/estimates')
            ->assertInertia(fn (Assert $page) => $page
                ->has('estimates.data', 1)
                ->where('estimates.data.0.id', $merged->id));
    }
}
