<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateItemAttachment;
use App\Models\EstimateItemComment;
use App\Models\Job;
use App\Models\Project;
use App\Models\User;
use App\Services\Scheduling\ScheduleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The web Job Detail page's "Field Notes & Photos" card — task-wise, then
 * material-wise. Per-material notes/photos (`estimate_item_comments`/
 * `estimate_item_attachments`) are what the mobile Materials screen writes
 * to now; this is the manager-facing, read-only mirror of that.
 */
class JobShowFieldNotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function makeJobWithTask(): array
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
        $site = $client->addresses()->create([
            'label' => 'Tower',
            'address' => '1 Harbor Way',
            'is_primary' => true,
            'position' => 0,
        ]);
        $job = Job::create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'name' => 'Riser rewire',
            'client' => 'Harborview',
            'status' => 'in-progress',
        ]);
        $job->addresses()->sync([$site->id => ['position' => 0]]);

        $schedule = app(ScheduleBuilder::class)->build($job, $user, withTasks: true);
        $task = $schedule->tasks()->first();

        return [$user, $job, $task];
    }

    private function makeMaterial(\App\Models\JobTask $task, string $description): EstimateItem
    {
        $estimate = Estimate::create([
            'number' => 'EST-'.random_int(1000, 9999),
            'client' => 'Test Client',
            'project' => 'Test Project',
            'issued_on' => now()->toDateString(),
            'amount' => 100,
            'status' => 'draft',
        ]);

        return EstimateItem::create([
            'estimate_id' => $estimate->id,
            'job_task_id' => $task->id,
            'category' => EstimateItem::CATEGORY_MATERIAL,
            'description' => $description,
            'unit' => 'ea',
            'quantity' => 1,
            'unit_cost' => 45,
        ]);
    }

    public function test_a_materials_own_note_and_photo_appear_nested_under_its_task(): void
    {
        [$user, $job, $task] = $this->makeJobWithTask();
        $item = $this->makeMaterial($task, 'Hubbel CFB2G30RCR floor box');

        $note = EstimateItemComment::create([
            'estimate_item_id' => $item->id,
            'user_id' => $user->id,
            'body' => 'Wrong finish delivered.',
        ]);
        $attachment = EstimateItemAttachment::create([
            'estimate_item_id' => $item->id,
            'uploaded_by' => $user->id,
            'name' => 'floor-box.jpg',
            'path' => 'estimate-item-attachments/'.$item->id.'/floor-box.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 12345,
        ]);
        Storage::disk('local')->put($attachment->path, 'fake-bytes');

        $response = $this->actingAs($user)->get(route('jobs.show', $job));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('JobShow')
            ->where('job.tasks.0.materialLines.0.description', 'Hubbel CFB2G30RCR floor box')
            ->where('job.tasks.0.materialLines.0.comments.0.body', 'Wrong finish delivered.')
            ->where('job.tasks.0.materialLines.0.comments.0.author', $user->name)
            ->where('job.tasks.0.materialLines.0.attachments.0.name', 'floor-box.jpg')
        );

        $photoUrl = $response->viewData('page')['props']['job']['tasks'][0]['materialLines'][0]['attachments'][0]['url'];
        $this->actingAs($user)->get($photoUrl)->assertOk();
    }

    public function test_a_labor_line_never_appears_as_a_material(): void
    {
        [$user, $job, $task] = $this->makeJobWithTask();
        EstimateItem::create([
            'estimate_id' => Estimate::create([
                'number' => 'EST-'.random_int(1000, 9999),
                'client' => 'Test Client',
                'project' => 'Test Project',
                'issued_on' => now()->toDateString(),
                'amount' => 100,
                'status' => 'draft',
            ])->id,
            'job_task_id' => $task->id,
            'category' => EstimateItem::CATEGORY_LABOR,
            'description' => 'Install labor',
            'unit' => 'hr',
            'quantity' => 1,
            'unit_cost' => 90,
        ]);

        $response = $this->actingAs($user)->get(route('jobs.show', $job));

        $materialLines = $response->viewData('page')['props']['job']['tasks'][0]['materialLines'];
        $this->assertSame([], $materialLines);
    }

    public function test_someone_who_does_not_own_the_job_cannot_fetch_a_material_photo(): void
    {
        [$user, $job, $task] = $this->makeJobWithTask();
        $item = $this->makeMaterial($task, 'Hubbel CFB2G30RCR floor box');
        $attachment = EstimateItemAttachment::create([
            'estimate_item_id' => $item->id,
            'uploaded_by' => $user->id,
            'name' => 'floor-box.jpg',
            'path' => 'estimate-item-attachments/'.$item->id.'/floor-box.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 12345,
        ]);
        Storage::disk('local')->put($attachment->path, 'fake-bytes');

        $outsider = User::factory()->create(['role' => 'Project Manager']);

        $this->actingAs($outsider)
            ->get(route('estimate-items.attachments.show', ['item' => $item->id, 'attachment' => $attachment->id]))
            ->assertForbidden();
    }
}
