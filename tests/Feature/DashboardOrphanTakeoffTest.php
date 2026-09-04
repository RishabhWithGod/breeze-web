<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A takeoff whose project is gone must not take the dashboard down.
 *
 * `ai_results.project_id` is ON DELETE CASCADE, so in principle this cannot
 * happen — and in practice it does: rows survive a project removed outside the
 * app, by a script, a restore, or a delete run with foreign key checks off. The
 * dashboard read `$result->project->name` on nothing and returned a 500 for the
 * whole page, for every user, because of one stale row.
 */
class DashboardOrphanTakeoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_survives_a_takeoff_whose_project_is_gone(): void
    {
        $user = User::factory()->create(['role' => 'Project Manager']);
        $client = $user->clients()->create(['name' => 'Harborview']);

        $project = Project::create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'name' => 'Data Hall',
            'client' => 'Harborview',
            'status' => 'draft',
        ]);
        $upload = $project->uploads()->create([
            'user_id' => $user->id,
            'name' => 'E-101.pdf',
            'format' => 'PDF',
            'size_bytes' => 1024,
            'status' => 'completed',
        ]);
        $orphan = AiResult::create([
            'ai_job_id' => AiJob::create([
                'project_id' => $project->id,
                'upload_id' => $upload->id,
                'user_id' => $user->id,
                'status' => 'completed',
            ])->id,
            'project_id' => $project->id,
            'upload_id' => $upload->id,
            'original_payload' => [],
            'review_status' => AiResult::REVIEW_IN_PROGRESS,
            'received_at' => now(),
        ]);

        /*
         * The project removed the way it actually happens: outside the app,
         * with the constraint stood down, so the result is left pointing at
         * nothing. Recreating the state is the only way to test the guard.
         */
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        DB::table('projects')->where('id', $project->id)->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');

        $this->assertNull($orphan->fresh()->project);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Left out rather than listed as "Unknown": the policy refuses a
                // review with no project, so offering it here would be offering
                // a decision nobody is allowed to make.
                ->where('reviewsNeedingAttention', []));
    }
}
