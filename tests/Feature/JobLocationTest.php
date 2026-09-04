<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\Project;
use App\Models\TeamMember;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A job's site: the address, the point behind it, and the place it came from.
 *
 * The job keeps a snapshot rather than reading through the client's book —
 * scheduling, time tracking, a printed job sheet and the crew app's GPS
 * check-in all read these columns. So the rule is that the snapshot always
 * matches the site it was taken from, and that nothing ever nulls it by
 * accident.
 */
class JobLocationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Project $project;

    private ClientAddress $harbour;

    private ClientAddress $dock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'Project Manager']);
        $this->client = $this->user->clients()->create(['name' => 'Harborview']);

        $this->harbour = $this->client->addresses()->create([
            'label' => 'Main building',
            'address' => '41 Harbor Way, Seattle, WA 98101, USA',
            'latitude' => 47.6062,
            'longitude' => -122.3421,
            'place_id' => 'ChIJharbour',
            'is_primary' => true,
            'position' => 0,
        ]);

        $this->dock = $this->client->addresses()->create([
            'label' => 'Warehouse',
            'address' => '9 Dock Road, Seattle, WA 98134, USA',
            'latitude' => 47.5801,
            'longitude' => -122.3300,
            'place_id' => 'ChIJdock',
            'position' => 1,
        ]);

        $this->project = $this->user->projects()->create([
            'client_id' => $this->client->id,
            'name' => 'Phase 1',
            'client' => 'Harborview',
            'status' => 'draft',
        ]);
    }

    public function test_creating_a_job_takes_the_whole_place_from_the_site_it_was_given(): void
    {
        $this->actingAs($this->user)->post(route('jobs.store'), [
            'name' => 'Harborview Fit-out',
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'address_ids' => [$this->harbour->id],
            'upload_id' => $this->drawing()->id,
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-21',
        ])->assertSessionHasNoErrors();

        $job = Job::sole();

        $this->assertSame('41 Harbor Way, Seattle, WA 98101, USA', $job->location);
        $this->assertSame('47.6062000', $job->latitude);
        $this->assertSame('-122.3421000', $job->longitude);
        $this->assertSame('ChIJharbour', $job->place_id);
    }

    public function test_a_job_at_a_site_google_never_matched_is_saved_without_a_point(): void
    {
        $typed = $this->client->addresses()->create([
            'label' => 'Back lot', 'address' => 'Behind the old mill, Route 9', 'position' => 2,
        ]);

        $this->actingAs($this->user)->post(route('jobs.store'), [
            'name' => 'Route 9 service call',
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'address_ids' => [$typed->id],
            'upload_id' => $this->drawing()->id,
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-21',
        ])->assertSessionHasNoErrors();

        $job = Job::sole();

        // An address with no point is a real address. The crew app has to read
        // this as "no geofence", not as the origin.
        $this->assertSame('Behind the old mill, Route 9', $job->location);
        $this->assertNull($job->latitude);
        $this->assertNull($job->place_id);
    }

    public function test_moving_a_job_to_another_site_moves_all_three_values(): void
    {
        $job = $this->makeJob($this->harbour);

        $this->actingAs($this->user)->put(route('jobs.update', $job), [
            'name' => $job->name,
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'address_ids' => [$this->dock->id],
            'status' => 'planning',
        ])->assertSessionHasNoErrors();

        $job->refresh();

        $this->assertSame('9 Dock Road, Seattle, WA 98134, USA', $job->location);
        $this->assertSame('47.5801000', $job->latitude);
        $this->assertSame('ChIJdock', $job->place_id);
    }

    public function test_editing_a_job_without_touching_its_site_leaves_the_point_intact(): void
    {
        $job = $this->makeJob($this->harbour);

        $this->actingAs($this->user)->put(route('jobs.update', $job), [
            'name' => 'Harborview Fit-out, phase 2',
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'address_ids' => [$this->harbour->id],
            'status' => 'planning',
        ])->assertSessionHasNoErrors();

        $job->refresh();

        // Renaming a job must never quietly unlocate it.
        $this->assertSame('Harborview Fit-out, phase 2', $job->name);
        $this->assertSame('47.6062000', $job->latitude);
        $this->assertSame('-122.3421000', $job->longitude);
        $this->assertSame('ChIJharbour', $job->place_id);
    }

    public function test_the_edit_screen_shows_the_site_the_job_is_already_at(): void
    {
        $job = $this->makeJob($this->harbour);

        $this->actingAs($this->user)
            ->get(route('jobs.edit', $job))
            ->assertInertia(fn ($page) => $page
                ->component('JobEdit')
                ->where('job.addressIds', [$this->harbour->id])
                ->where('job.latitude', 47.6062)
                ->where('job.placeId', 'ChIJharbour')
                // And the sites it can be moved to, each with its own place.
                ->where('clients.0.addresses.0.placeId', 'ChIJharbour'));
    }

    public function test_the_crew_app_is_given_the_point_it_will_check_in_against(): void
    {
        $job = $this->makeJob($this->harbour);
        $job->teamMembers()->attach(
            TeamMember::create(['user_id' => $this->user->id, 'name' => 'Dana', 'initials' => 'DW', 'role' => 'Electrician'])->id,
        );

        $this->actingAs($this->user, 'sanctum')
            ->getJson(route('api.v1.jobs.show', $job))
            ->assertOk()
            ->assertJsonPath('data.latitude', 47.6062)
            ->assertJsonPath('data.longitude', -122.3421)
            ->assertJsonPath('data.placeId', 'ChIJharbour');
    }

    public function test_a_site_that_is_not_the_clients_is_refused(): void
    {
        $theirs = $this->user->clients()->create(['name' => 'Someone Else']);
        $theirSite = $theirs->addresses()->create(['label' => 'Main', 'address' => 'Elsewhere']);

        $this->actingAs($this->user)->post(route('jobs.store'), [
            'name' => 'Borrowed site',
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'address_ids' => [$theirSite->id],
            'upload_id' => $this->drawing()->id,
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-21',
        ])->assertSessionHasErrors('address_ids');

        $this->assertSame(0, Job::count());
    }

    private function makeJob(ClientAddress $site): Job
    {
        $job = Job::create([
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'name' => 'Harborview Fit-out',
            'client' => 'Harborview',
            'location' => $site->address,
            'latitude' => $site->latitude,
            'longitude' => $site->longitude,
            'place_id' => $site->place_id,
            'status' => 'planning',
        ]);

        $job->addresses()->attach($site->id, ['position' => 0]);

        return $job;
    }

    private function drawing(): Upload
    {
        return $this->project->uploads()->create([
            'user_id' => $this->user->id,
            'name' => 'E-101.pdf',
            'format' => 'PDF',
            'size_bytes' => 1024,
            'status' => 'completed',
        ]);
    }

    /*
     * When the work runs.
     *
     * Required from here on: a job with no dates cannot be scheduled or crewed,
     * and shows as a blank row on every calendar in the app.
     */
    public function test_a_job_needs_the_days_it_runs(): void
    {
        $this->actingAs($this->user)
            ->post(route('jobs.store'), [
                'name' => 'Dock rewire',
                'client_id' => $this->client->id,
                'project_id' => $this->project->id,
                'address_ids' => [$this->harbour->id],
                'upload_id' => $this->drawing()->id,
            ])
            ->assertSessionHasErrors(['start_date', 'end_date']);

        $this->assertSame(0, Job::count());
    }

    public function test_a_job_cannot_finish_before_it_starts(): void
    {
        $this->actingAs($this->user)
            ->post(route('jobs.store'), [
                'name' => 'Dock rewire',
                'client_id' => $this->client->id,
                'project_id' => $this->project->id,
                'address_ids' => [$this->harbour->id],
                'upload_id' => $this->drawing()->id,
                'start_date' => '2026-09-21',
                'end_date' => '2026-09-07',
            ])
            ->assertSessionHasErrors('end_date');

        $this->assertSame(0, Job::count());
    }

    /**
     * What a drawing was priced at reaches the Create Job screen.
     *
     * The form fills the budget from it the moment a drawing is picked — a job's
     * budget is the estimate signed off on that drawing, and retyping a figure
     * that is already on record is how the two drift apart. The form can only do
     * that if the amount is on the screen with the drawing.
     */
    public function test_the_create_job_screen_carries_what_each_drawing_was_priced_at(): void
    {
        $drawing = $this->drawing();
        $result = AiResult::create([
            'ai_job_id' => AiJob::create([
                'project_id' => $this->project->id,
                'upload_id' => $drawing->id,
                'user_id' => $this->user->id,
                'status' => 'completed',
            ])->id,
            'project_id' => $this->project->id,
            'upload_id' => $drawing->id,
            'original_payload' => [],
        ]);
        $estimate = Estimate::create([
            'ai_result_id' => $result->id,
            'number' => 'EST-9100',
            'client' => 'Harborview',
            'project' => 'Data Hall',
            'issued_on' => now()->toDateString(),
            'amount' => 12750,
            'grand_total' => 12750,
            'status' => 'draft',
        ]);
        $result->update(['estimate_id' => $estimate->id]);

        $this->actingAs($this->user)
            ->get(route('jobs.create'))
            ->assertInertia(function ($page) use ($drawing) {
                $upload = collect($page->toArray()['props']['uploads'])
                    ->firstWhere('id', $drawing->id);

                $this->assertSame('EST-9100', $upload['estimate']['number']);
                $this->assertEqualsWithDelta(12750, $upload['estimate']['amount'], 0.001);
            });
    }

    /** A drawing nobody has priced yet says so, so the field is left alone. */
    public function test_a_drawing_with_no_estimate_carries_none(): void
    {
        $drawing = $this->drawing();

        $this->actingAs($this->user)
            ->get(route('jobs.create'))
            ->assertInertia(function ($page) use ($drawing) {
                $upload = collect($page->toArray()['props']['uploads'])
                    ->firstWhere('id', $drawing->id);

                $this->assertNull($upload['estimate']);
            });
    }
}
