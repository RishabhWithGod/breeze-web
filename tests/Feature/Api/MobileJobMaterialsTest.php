<?php

namespace Tests\Feature\Api;

use App\Models\EstimateItem;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobAttachment;
use App\Models\JobFieldMaterial;
use App\Models\JobSchedule;
use App\Models\PriceBookItem;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Material and Work Changes: actual use against the plan, materials added on
 * site, the commodity list, and evidence photos.
 */
class MobileJobMaterialsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $foreman;

    private User $journeyman;

    private Job $job;

    private EstimateItem $cement;

    private EstimateItem $steel;

    private EstimateItem $labor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'Project Manager']);
        $team = Team::create(['name' => 'North']);
        $this->job = Job::create([
            'user_id' => $this->manager->id, 'team_id' => $team->id, 'name' => 'Slab', 'client' => 'C',
            'status' => 'in-progress', 'start_date' => now()->toDateString(),
        ]);
        $schedule = JobSchedule::create(['job_id' => $this->job->id, 'working_days' => [1, 2, 3, 4, 5]]);

        $this->foreman = $this->crew('Dana', 'Foreman', 'foreman', $team->id);
        $this->journeyman = $this->crew('Priya', 'Journeyman', 'journeyman', $team->id);

        $task = $schedule->tasks()->create([
            'job_id' => $this->job->id, 'job_schedule_id' => $schedule->id, 'title' => 'Pour', 'position' => 0,
            'status' => 'pending', 'foreman_id' => $this->journeyman->foreman->id, 'supervisor_id' => $this->foreman->foreman->id,
        ]);
        $estimate = \App\Models\Estimate::create([
            'number' => 'E1', 'client' => 'C', 'project' => 'P', 'issued_on' => now()->toDateString(), 'amount' => 1, 'status' => 'draft',
        ]);
        $make = fn (string $cat, string $desc, string $unit, float $qty) => EstimateItem::create([
            'estimate_id' => $estimate->id, 'job_task_id' => $task->id, 'category' => $cat,
            'description' => $desc, 'unit' => $unit, 'quantity' => $qty, 'unit_cost' => 1,
        ]);
        $this->cement = $make('material', 'Cement', 'bag', 200);
        $this->steel = $make('material', 'TMT Steel', 'kg', 1500);
        $this->labor = $make('labor', 'Pour slab', 'hr', 8);
    }

    private function crew(string $name, string $webRole, string $registerRole, int $teamId): User
    {
        $user = User::factory()->create([
            'name' => $name, 'role' => $webRole, 'registration_source' => User::SOURCE_MOBILE, 'status' => User::STATUS_ACTIVE,
        ]);
        $row = new Foreman(['name' => $name, 'initials' => substr($name, 0, 2), 'team_id' => $teamId, 'role' => $registerRole]);
        $row->user_id = $user->id;
        $row->save();

        return $user->refresh();
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken);
    }

    public function test_the_planned_list_has_materials_only_and_flags_nothing_until_reported(): void
    {
        $res = $this->as($this->journeyman)->getJson("/api/v1/jobs/{$this->job->id}/materials")->assertOk();

        $res->assertJsonCount(2, 'data.materials');
        $res->assertJsonPath('data.materials.0.description', 'Cement');
        $res->assertJsonPath('data.materials.0.plannedQty', 200);
        $res->assertJsonPath('data.materials.0.actualQty', null);
        $res->assertJsonPath('data.materials.0.canEdit', true);
        $res->assertJsonPath('data.needsReviewCount', 0);
        $this->assertNotContains('Pour slab', array_column($res->json('data.materials'), 'description'));
    }

    public function test_submitting_actuals_and_added_materials_flags_exceptions_and_is_replay_safe(): void
    {
        $body = [
            'materials' => [
                ['estimate_item_id' => $this->cement->id, 'actual_quantity' => 185],          // under plan
                ['estimate_item_id' => $this->steel->id, 'actual_quantity' => 1620, 'reason' => 'Extra bars'], // over plan
            ],
            'added' => [
                ['client_key' => 'abc-1', 'description' => 'PVC pipe', 'unit' => 'm', 'actual_quantity' => 12],
            ],
        ];

        $first = $this->as($this->journeyman)->postJson("/api/v1/jobs/{$this->job->id}/materials", $body)->assertOk();
        $first->assertJsonPath('data.materials.0.exception', 'under');
        $first->assertJsonPath('data.materials.1.exception', 'over');
        $first->assertJsonPath('data.materials.1.reason', 'Extra bars');
        $first->assertJsonCount(1, 'data.added');
        // One over-plan line plus one added material need review; the under-plan one does not.
        $first->assertJsonPath('data.needsReviewCount', 2);

        // Sending the very same thing again changes nothing.
        $again = $this->as($this->journeyman)->postJson("/api/v1/jobs/{$this->job->id}/materials", $body)->assertOk();
        $again->assertJsonCount(1, 'data.added');
        $this->assertSame(3, JobFieldMaterial::count());

        // Clearing a quantity puts the line back to "not reported yet".
        $this->as($this->journeyman)->postJson("/api/v1/jobs/{$this->job->id}/materials", [
            'materials' => [['estimate_item_id' => $this->cement->id, 'actual_quantity' => null]],
        ])->assertOk()->assertJsonPath('data.materials.0.actualQty', null);
    }

    public function test_a_journeyman_cannot_report_against_a_task_that_is_not_theirs_but_a_foreman_can(): void
    {
        $other = $this->crew('Other', 'Journeyman', 'journeyman', Team::first()->id);
        // Staffed on the job through another task so they can see it, but not on this line's task.
        $task2 = $this->job->schedule->tasks()->create([
            'job_id' => $this->job->id, 'job_schedule_id' => $this->job->schedule->id, 'title' => 'Other', 'position' => 1,
            'status' => 'pending', 'foreman_id' => $other->foreman->id,
        ]);
        $this->assertNotNull($task2);

        $this->as($other)->postJson("/api/v1/jobs/{$this->job->id}/materials", [
            'materials' => [['estimate_item_id' => $this->cement->id, 'actual_quantity' => 5]],
        ])->assertForbidden();

        $this->as($this->foreman)->postJson("/api/v1/jobs/{$this->job->id}/materials", [
            'materials' => [['estimate_item_id' => $this->cement->id, 'actual_quantity' => 5]],
        ])->assertOk();

        $list = $this->as($other)->getJson("/api/v1/jobs/{$this->job->id}/materials")->assertOk();
        $this->assertFalse($list->json('data.materials.0.canEdit'));
    }

    public function test_a_line_from_another_job_is_refused_and_a_locked_job_is_read_only(): void
    {
        $this->as($this->journeyman)->postJson("/api/v1/jobs/{$this->job->id}/materials", [
            'materials' => [['estimate_item_id' => 999999, 'actual_quantity' => 1]],
        ])->assertStatus(422);

        $this->job->forceFill(['status' => 'completed'])->save();
        $this->as($this->journeyman)->postJson("/api/v1/jobs/{$this->job->id}/materials", [
            'added' => [['client_key' => 'k', 'description' => 'x', 'actual_quantity' => 1]],
        ])->assertStatus(409);
    }

    public function test_only_who_added_a_material_or_a_foreman_can_remove_it(): void
    {
        $this->as($this->journeyman)->postJson("/api/v1/jobs/{$this->job->id}/materials", [
            'added' => [['client_key' => 'k1', 'description' => 'Tape', 'actual_quantity' => 2]],
        ])->assertOk();
        $entry = JobFieldMaterial::sole();

        $other = $this->crew('Other', 'Journeyman', 'journeyman', Team::first()->id);
        $this->job->schedule->tasks()->create([
            'job_id' => $this->job->id, 'job_schedule_id' => $this->job->schedule->id, 'title' => 'O', 'position' => 1,
            'status' => 'pending', 'foreman_id' => $other->foreman->id,
        ]);
        $this->as($other)->deleteJson("/api/v1/jobs/{$this->job->id}/materials/{$entry->id}")->assertForbidden();
        $this->as($this->foreman)->deleteJson("/api/v1/jobs/{$this->job->id}/materials/{$entry->id}")->assertOk();
        $this->assertSame(0, JobFieldMaterial::count());
    }

    public function test_the_commodity_list_is_searchable_and_hides_prices(): void
    {
        $owner = \App\Support\Ownership::bookOwnerId($this->manager->id);
        PriceBookItem::create(['user_id' => $owner, 'match_key' => 'a', 'description' => 'PVC conduit 25mm', 'unit' => 'm', 'section' => 'Conduit', 'unit_material_cost' => 3]);
        PriceBookItem::create(['user_id' => $owner, 'match_key' => 'b', 'description' => 'Copper wire', 'unit' => 'm', 'section' => 'Wire', 'unit_material_cost' => 9]);

        $company = \App\Models\CompanyProfile::create([
            'user_id' => $this->manager->id, 'name' => 'Co', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'co@x.test', 'timezone' => 'America/Chicago',
        ]);
        $this->manager->forceFill(['company_id' => $company->id])->save();

        $res = $this->as($this->manager)->getJson('/api/v1/commodities?search=pvc')->assertOk();
        $res->assertJsonCount(1, 'data.items');
        $res->assertJsonPath('data.items.0.description', 'PVC conduit 25mm');
        $res->assertJsonMissingPath('data.items.0.materialPrice');
    }

    public function test_photos_are_filed_as_job_attachments_so_the_web_shows_them(): void
    {
        Storage::fake('local');

        $res = $this->as($this->journeyman)->postJson("/api/v1/jobs/{$this->job->id}/photos", [
            'photo' => UploadedFile::fake()->image('wall.jpg'),
        ])->assertCreated();

        $res->assertJsonPath('data.mine', true);
        $this->assertSame(1, JobAttachment::count());
        $this->assertSame($this->job->id, JobAttachment::first()->job_id);

        $this->as($this->journeyman)->getJson("/api/v1/jobs/{$this->job->id}/photos")->assertJsonCount(1, 'data.photos');
        $this->as($this->journeyman)->get("/api/v1/jobs/{$this->job->id}/photos/".JobAttachment::first()->id)->assertOk();

        // A non-image is refused.
        $this->as($this->journeyman)->postJson("/api/v1/jobs/{$this->job->id}/photos", [
            'photo' => UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf'),
        ])->assertStatus(422);
    }
}
