<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Estimate;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Leaving the takeoff flow half-way through, and finding the way back.
 *
 * The flow runs over several screens and often several days. What is remembered
 * is only which takeoff it was; where to resume is read off the takeoff every
 * time, so the link cannot point at a step that has since been completed.
 */
class ResumeTakeoffFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $client;

    private AiResult $result;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'Project Manager']);

        $this->client = $this->user->projects()->create([
            'name' => 'Harborview', 'client' => 'Harborview', 'status' => 'draft',
        ]);

        $this->result = AiResult::create([
            'ai_job_id' => AiJob::create([
                'project_id' => $this->client->id,
                'user_id' => $this->user->id,
                'status' => 'completed',
            ])->id,
            'project_id' => $this->client->id,
            'original_payload' => [],
        ]);
    }

    public function test_nothing_is_offered_before_the_flow_is_entered(): void
    {
        $this->actingAs($this->user)
            ->get(route('jobs.index'))
            ->assertInertia(fn (Assert $page) => $page->where('takeoffFlow', null));
    }

    public function test_the_flow_starts_when_the_client_is_created(): void
    {
        $this->actingAs($this->user)->post('/projects', [
            'name' => 'Northgate Depot',
            'addresses' => [['label' => 'Depot', 'address' => '9 Depot Road']],
        ]);

        // The takeoff starts at the client, not at the review: its drawing is
        // the next step, and the button has to be there from the beginning.
        $this->actingAs($this->user)
            ->get(route('jobs.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('takeoffFlow.stage', 'Upload')
                ->where('takeoffFlow.projectName', 'Northgate Depot'));
    }

    public function test_an_uploaded_drawing_with_nothing_back_yet_resumes_at_the_analysis(): void
    {
        $this->client->uploads()->create([
            'user_id' => $this->user->id,
            'name' => 'E-101.pdf',
            'format' => 'PDF',
            'size_bytes' => 1024,
            'status' => 'completed',
        ]);

        // Nothing has come back from the engine, so the processing screen is
        // where that is said — and where it is resubmitted.
        $this->result->delete();
        $this->actingAs($this->user)->get(route('projects.show', $this->client));
        session(['takeoff_flow_project_id' => $this->client->id]);

        $this->actingAs($this->user)
            ->get(route('jobs.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('takeoffFlow.resumeUrl', route('processing.show', $this->client))
                ->where('takeoffFlow.stage', 'Analysis'));
    }

    public function test_starting_a_second_client_is_flagged_first(): void
    {
        $this->actingAs($this->user)->get(route('reviews.show', $this->result));

        $this->actingAs($this->user)
            ->get(route('projects.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('unfinishedTakeoff.projectName', 'Harborview')
                ->where('unfinishedTakeoff.stage', 'Review'));
    }

    public function test_every_screen_that_can_start_a_takeoff_says_one_is_running(): void
    {
        $this->actingAs($this->user)->get(route('reviews.show', $this->result));

        // Uploading another client's drawing, and raising a job by hand, both
        // fork the flow — so both say what is already running.
        foreach ([route('uploads.create'), route('jobs.create')] as $url) {
            $this->actingAs($this->user)
                ->get($url)
                ->assertInertia(fn (Assert $page) => $page
                    ->where('unfinishedTakeoff.projectName', 'Harborview')
                    ->where('unfinishedTakeoff.stage', 'Review'));
        }
    }

    public function test_the_upload_screen_opened_for_the_very_client_being_resumed_says_nothing(): void
    {
        $this->actingAs($this->user)->get(route('reviews.show', $this->result));

        // That is the resume, not a second start.
        $this->actingAs($this->user)
            ->get(route('uploads.create', ['project' => $this->client->id]))
            ->assertInertia(fn (Assert $page) => $page->where('unfinishedTakeoff', null));
    }

    public function test_creating_the_second_client_moves_the_flow_onto_it(): void
    {
        $this->actingAs($this->user)->get(route('reviews.show', $this->result));

        $this->actingAs($this->user)->post('/projects', [
            'name' => 'Northgate Depot',
            'addresses' => [['label' => 'Depot', 'address' => '9 Depot Road']],
        ]);

        // The first one is untouched — it just is not the one being resumed.
        $this->actingAs($this->user)
            ->get(route('jobs.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('takeoffFlow.projectName', 'Northgate Depot')
                ->where('takeoffFlow.stage', 'Upload'));

        $this->assertNotNull($this->result->fresh());
    }

    public function test_leaving_the_review_leaves_a_way_back_to_it(): void
    {
        $this->actingAs($this->user)->get(route('reviews.show', $this->result));

        // Off to look at something else entirely.
        $this->actingAs($this->user)
            ->get(route('jobs.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('takeoffFlow.resumeUrl', route('reviews.show', $this->result))
                ->where('takeoffFlow.stage', 'Review')
                ->where('takeoffFlow.projectName', 'Harborview'));
    }

    public function test_the_flow_s_own_screens_do_not_offer_to_take_you_where_you_are(): void
    {
        // The roadmap at the top of those screens already says where you are.
        $this->actingAs($this->user)
            ->get(route('reviews.show', $this->result))
            ->assertInertia(fn (Assert $page) => $page->where('takeoffFlow', null));
    }

    public function test_the_way_back_follows_the_takeoff_rather_than_the_last_screen(): void
    {
        $this->actingAs($this->user)->get(route('reviews.show', $this->result));

        // Signed off elsewhere: the button has to move on with the takeoff, not
        // point back at a review that is finished.
        $this->result->update([
            'review_status' => AiResult::REVIEW_FINALISED,
            'finalised_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->get(route('jobs.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('takeoffFlow.resumeUrl', route('finals.show', $this->result))
                ->where('takeoffFlow.stage', 'Estimate'));
    }

    public function test_with_an_estimate_raised_the_next_step_is_the_job(): void
    {
        $this->actingAs($this->user)->get(route('reviews.show', $this->result));

        $estimate = Estimate::create([
            'ai_result_id' => $this->result->id,
            'number' => 'EST-9001',
            'client' => 'Harborview',
            'project' => 'Harborview',
            'issued_on' => now()->toDateString(),
            'amount' => 100,
            'status' => 'draft',
        ]);
        $this->result->update([
            'review_status' => AiResult::REVIEW_FINALISED,
            'finalised_at' => now(),
            'estimate_id' => $estimate->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('jobs.index'))
            ->assertInertia(fn (Assert $page) => $page
                // In the flow, so the estimate opens with its roadmap.
                ->where('takeoffFlow.resumeUrl', route('estimates.show', ['estimate' => $estimate, 'flow' => 1]))
                ->where('takeoffFlow.stage', 'Job'));
    }

    public function test_with_a_job_raised_the_next_step_is_its_tasks(): void
    {
        $this->actingAs($this->user)->get(route('reviews.show', $this->result));

        $job = $this->makeJob();
        $this->result->update([
            'review_status' => AiResult::REVIEW_FINALISED,
            'finalised_at' => now(),
            'estimate_id' => $this->makeEstimate()->id,
            'work_job_id' => $job->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('jobs.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('takeoffFlow.resumeUrl', route('jobs.tasks.setup', $job))
                ->where('takeoffFlow.stage', 'Tasks'));
    }

    public function test_saving_the_tasks_ends_the_flow(): void
    {
        $job = $this->makeJob();
        $this->result->update([
            'review_status' => AiResult::REVIEW_FINALISED,
            'finalised_at' => now(),
            'estimate_id' => $this->makeEstimate()->id,
            'work_job_id' => $job->id,
        ]);

        $this->actingAs($this->user)->get(route('jobs.tasks.setup', $job));

        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $job), [
            'tasks' => [[
                'title' => 'Rough-in',
                'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            ]],
        ]);

        // The takeoff has become a job with its work laid out: nothing to resume.
        $this->actingAs($this->user)
            ->get(route('jobs.index'))
            ->assertInertia(fn (Assert $page) => $page->where('takeoffFlow', null));
    }

    public function test_the_reminder_can_be_put_away_without_touching_the_takeoff(): void
    {
        $this->actingAs($this->user)->get(route('reviews.show', $this->result));

        $this->actingAs($this->user)->delete(route('takeoff-flow.forget'));

        $this->actingAs($this->user)
            ->get(route('jobs.index'))
            ->assertInertia(fn (Assert $page) => $page->where('takeoffFlow', null));

        // The takeoff is untouched, and opening it again brings the way back.
        $this->assertNotNull($this->result->fresh());

        $this->actingAs($this->user)->get(route('reviews.show', $this->result));

        $this->actingAs($this->user)
            ->get(route('jobs.index'))
            ->assertInertia(fn (Assert $page) => $page->where('takeoffFlow.stage', 'Review'));
    }

    public function test_a_deleted_client_leaves_nothing_to_resume(): void
    {
        $this->actingAs($this->user)->get(route('reviews.show', $this->result));

        $this->client->delete();

        $this->actingAs($this->user)
            ->get(route('jobs.index'))
            ->assertInertia(fn (Assert $page) => $page->where('takeoffFlow', null));
    }

    private function makeJob(): Job
    {
        return Job::create([
            'project_id' => $this->client->id,
            'ai_result_id' => $this->result->id,
            'name' => 'Harborview Fit-out',
            'client' => 'Harborview',
            'location' => '41 Harbor Way',
            'status' => 'planning',
        ]);
    }

    private function makeEstimate(): Estimate
    {
        return Estimate::create([
            'ai_result_id' => $this->result->id,
            'number' => 'EST-9002',
            'client' => 'Harborview',
            'project' => 'Harborview',
            'issued_on' => now()->toDateString(),
            'amount' => 100,
            'status' => 'draft',
        ]);
    }
}
