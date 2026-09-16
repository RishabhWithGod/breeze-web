<?php

namespace Tests\Feature\Api;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobTask;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Scheduling\ScheduleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * One material line's own notes and photos — the per-line replacement for
 * the Materials screen's old single task-wide note/photo composer
 * (`MobileTaskNotesAndPhotosTest` covers that still-existing, now-unused-by
 * -mobile pair). Same authority and lock rules, derived through the line's
 * own task exactly like `EstimateItemController::setCompletion()` already
 * does.
 */
class MobileEstimateItemNotesAndPhotosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function makeJob(array $attributes = []): Job
    {
        return Job::create([
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside Office Renovation',
            'client' => 'Riverside Properties LLC',
            'job_type' => 'commercial',
            'status' => 'in-progress',
            ...$attributes,
        ]);
    }

    private function makeElectrician(string $name = 'Priya Raman'): array
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'Electrician']);
        $member = TeamMember::create(['name' => $name, 'initials' => 'PR', 'role' => 'Electrician', 'user_id' => $user->id]);

        return [$user, $member];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function staffOnTask(Job $job, TeamMember $member): JobTask
    {
        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $task = $schedule->tasks()->first();
        $task->assignments()->create(['team_member_id' => $member->id, 'role' => 'electrician']);

        return $task;
    }

    private function makeMaterialOnTask(JobTask $task): EstimateItem
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
            'description' => 'Hubbel CFB2G30RCR floor box',
            'unit' => 'ea',
            'quantity' => 1,
            'unit_cost' => 45,
        ]);
    }

    /* --------------------------------------------------------------- notes */

    public function test_an_electrician_can_add_a_note_to_one_material_line(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $task = $this->staffOnTask($job, $member);
        $item = $this->makeMaterialOnTask($task);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/estimate-items/{$item->id}/notes", ['body' => 'Wrong finish delivered.'])
            ->assertStatus(201)
            ->assertJsonPath('data.body', 'Wrong finish delivered.')
            ->assertJsonPath('data.author', 'Priya Raman');

        $this->assertDatabaseHas('estimate_item_comments', [
            'estimate_item_id' => $item->id,
            'body' => 'Wrong finish delivered.',
        ]);
    }

    public function test_a_note_on_one_material_never_appears_under_another(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $task = $this->staffOnTask($job, $member);
        $itemA = $this->makeMaterialOnTask($task);
        $itemB = $this->makeMaterialOnTask($task);
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $this->withHeaders($headers)->postJson("/api/v1/estimate-items/{$itemA->id}/notes", ['body' => 'About A only.']);

        $this->withHeaders($headers)
            ->getJson("/api/v1/estimate-items/{$itemB->id}/notes")
            ->assertOk()
            ->assertJsonPath('data.notes', []);

        $this->withHeaders($headers)
            ->getJson("/api/v1/estimate-items/{$itemA->id}/notes")
            ->assertOk()
            ->assertJsonCount(1, 'data.notes');
    }

    public function test_an_empty_note_is_rejected(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $task = $this->staffOnTask($job, $member);
        $item = $this->makeMaterialOnTask($task);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/estimate-items/{$item->id}/notes", ['body' => ''])
            ->assertStatus(422);
    }

    public function test_an_electrician_cannot_note_a_material_on_a_task_they_are_not_assigned_to(): void
    {
        $job = $this->makeJob();
        [, $otherMember] = $this->makeElectrician('Other Tech');
        $task = $this->staffOnTask($job, $otherMember);
        $item = $this->makeMaterialOnTask($task);
        [$user] = $this->makeElectrician();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/estimate-items/{$item->id}/notes", ['body' => 'Not my task.'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('estimate_item_comments', ['estimate_item_id' => $item->id]);
    }

    /* ------------------------------------------------------------- photos */

    public function test_an_electrician_can_upload_a_photo_to_one_material_line(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $task = $this->staffOnTask($job, $member);
        $item = $this->makeMaterialOnTask($task);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->post("/api/v1/estimate-items/{$item->id}/attachments", [
                'file' => UploadedFile::fake()->image('floor-box.jpg', 200, 200),
            ], ['Accept' => 'application/json']);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'floor-box.jpg')
            ->assertJsonPath('data.uploadedBy', 'Priya Raman');

        $attachment = \App\Models\EstimateItemAttachment::sole();
        $this->assertSame($item->id, $attachment->estimate_item_id);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $task = $this->staffOnTask($job, $member);
        $item = $this->makeMaterialOnTask($task);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->post("/api/v1/estimate-items/{$item->id}/attachments", [
                'file' => UploadedFile::fake()->create('spec.pdf', 100, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_photos_list_includes_a_fetchable_url(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $task = $this->staffOnTask($job, $member);
        $item = $this->makeMaterialOnTask($task);
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $this->withHeaders($headers)->post("/api/v1/estimate-items/{$item->id}/attachments", [
            'file' => UploadedFile::fake()->image('site.jpg'),
        ], ['Accept' => 'application/json']);

        $listResponse = $this->withHeaders($headers)
            ->getJson("/api/v1/estimate-items/{$item->id}/attachments")
            ->assertOk();

        $photo = $listResponse->json('data.photos.0');
        $this->assertNotNull($photo['url']);

        $this->withHeaders($headers)->get($photo['url'])->assertOk();
    }

    public function test_an_electrician_cannot_upload_a_photo_to_a_material_on_a_task_they_are_not_assigned_to(): void
    {
        $job = $this->makeJob();
        [, $otherMember] = $this->makeElectrician('Other Tech');
        $task = $this->staffOnTask($job, $otherMember);
        $item = $this->makeMaterialOnTask($task);
        [$user] = $this->makeElectrician();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->post("/api/v1/estimate-items/{$item->id}/attachments", [
                'file' => UploadedFile::fake()->image('sneaky.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(403);

        $this->assertSame(0, \App\Models\EstimateItemAttachment::count());
    }

    public function test_fetching_a_photo_is_refused_to_someone_off_the_job(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $task = $this->staffOnTask($job, $member);
        $item = $this->makeMaterialOnTask($task);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->post("/api/v1/estimate-items/{$item->id}/attachments", [
                'file' => UploadedFile::fake()->image('site.jpg'),
            ], ['Accept' => 'application/json']);

        $attachment = \App\Models\EstimateItemAttachment::sole();
        [$outsider] = $this->makeElectrician('Outsider');

        // Laravel's `RequestGuard` caches whichever user it resolved for
        // the rest of the test process — without forgetting it, the
        // request below would silently keep authenticating as $user.
        auth()->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($outsider))
            ->get(route('api.v1.estimate-items.attachments.show', $attachment->id))
            ->assertStatus(403);
    }

    public function test_a_note_is_refused_once_the_job_is_locked(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob(['status' => 'completed']);
        $task = $this->staffOnTask($job, $member);
        $item = $this->makeMaterialOnTask($task);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/estimate-items/{$item->id}/notes", ['body' => 'Too late.'])
            ->assertStatus(409);
    }
}
