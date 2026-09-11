<?php

namespace Tests\Feature\Api;

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
 * Task-wise notes and photos — previously entirely unreachable from mobile:
 * `ApiConstants.jobNotesApiUrl`/`jobPhotosApiUrl` pointed at `/v1/jobs/{id}
 * /notes`/`/photos`, routes that were never registered at all. This is the
 * real, task-scoped replacement (`job_task_comments`/`job_task_attachments`
 * — schema that already existed, unused until now).
 */
class MobileTaskNotesAndPhotosTest extends TestCase
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

    /* --------------------------------------------------------------- notes */

    public function test_an_electrician_can_add_a_note_to_their_own_task(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $task = $this->staffOnTask($job, $member);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/tasks/{$task->id}/notes", ['body' => 'Missing two receptacles.'])
            ->assertStatus(201)
            ->assertJsonPath('data.body', 'Missing two receptacles.')
            ->assertJsonPath('data.author', 'Priya Raman');

        $this->assertDatabaseHas('job_task_comments', [
            'job_task_id' => $task->id,
            'body' => 'Missing two receptacles.',
        ]);
    }

    public function test_notes_come_back_oldest_first_with_the_authors_name(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $task = $this->staffOnTask($job, $member);
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $this->withHeaders($headers)->postJson("/api/v1/tasks/{$task->id}/notes", ['body' => 'First note']);
        $this->withHeaders($headers)->postJson("/api/v1/tasks/{$task->id}/notes", ['body' => 'Second note']);

        $response = $this->withHeaders($headers)
            ->getJson("/api/v1/tasks/{$task->id}/notes")
            ->assertOk();

        // Oldest first — a note thread reads top to bottom like a
        // conversation (`JobTask::comments()`'s own order), not
        // newest-on-top like an activity feed.
        $bodies = array_column($response->json('data.notes'), 'body');
        $this->assertSame(['First note', 'Second note'], $bodies);
    }

    public function test_an_empty_note_is_rejected(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $task = $this->staffOnTask($job, $member);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/tasks/{$task->id}/notes", ['body' => ''])
            ->assertStatus(422);
    }

    public function test_an_electrician_cannot_add_a_note_to_a_task_they_are_not_assigned_to(): void
    {
        $job = $this->makeJob();
        [, $otherMember] = $this->makeElectrician('Other Tech');
        $task = $this->staffOnTask($job, $otherMember);
        // Created *after* the schedule is built, so `ScheduleBuilder`'s own
        // auto-staffing (which matches existing crew by name) cannot have
        // put them on anything — the only way to prove "not assigned"
        // actually means not assigned.
        [$user] = $this->makeElectrician();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/tasks/{$task->id}/notes", ['body' => 'Not my task.'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('job_task_comments', ['job_task_id' => $task->id]);
    }

    /* ------------------------------------------------------------- photos */

    public function test_an_electrician_can_upload_a_photo_to_their_own_task(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $task = $this->staffOnTask($job, $member);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->post("/api/v1/tasks/{$task->id}/attachments", [
                'file' => UploadedFile::fake()->image('receptacle.jpg', 200, 200),
            ], ['Accept' => 'application/json']);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'receptacle.jpg')
            ->assertJsonPath('data.uploadedBy', 'Priya Raman');

        $attachment = \App\Models\JobTaskAttachment::sole();
        $this->assertSame($task->id, $attachment->job_task_id);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $task = $this->staffOnTask($job, $member);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->post("/api/v1/tasks/{$task->id}/attachments", [
                'file' => UploadedFile::fake()->create('spec.pdf', 100, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_photos_list_includes_a_fetchable_url(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $task = $this->staffOnTask($job, $member);
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $this->withHeaders($headers)->post("/api/v1/tasks/{$task->id}/attachments", [
            'file' => UploadedFile::fake()->image('site.jpg'),
        ], ['Accept' => 'application/json']);

        $listResponse = $this->withHeaders($headers)
            ->getJson("/api/v1/tasks/{$task->id}/attachments")
            ->assertOk();

        $photo = $listResponse->json('data.photos.0');
        $this->assertNotNull($photo['url']);

        $this->withHeaders($headers)->get($photo['url'])->assertOk();
    }

    public function test_an_electrician_cannot_upload_a_photo_to_a_task_they_are_not_assigned_to(): void
    {
        $job = $this->makeJob();
        [, $otherMember] = $this->makeElectrician('Other Tech');
        $task = $this->staffOnTask($job, $otherMember);
        // Created *after* the schedule is built — see the equivalent note
        // in the notes test above.
        [$user] = $this->makeElectrician();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->post("/api/v1/tasks/{$task->id}/attachments", [
                'file' => UploadedFile::fake()->image('sneaky.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(403);

        $this->assertSame(0, \App\Models\JobTaskAttachment::count());
    }

    public function test_fetching_a_photo_is_refused_to_someone_off_the_job(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $task = $this->staffOnTask($job, $member);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->post("/api/v1/tasks/{$task->id}/attachments", [
                'file' => UploadedFile::fake()->image('site.jpg'),
            ], ['Accept' => 'application/json']);

        $attachment = \App\Models\JobTaskAttachment::sole();
        [$outsider] = $this->makeElectrician('Outsider');

        // Laravel's `RequestGuard` caches whichever user it resolved for
        // the rest of the test process (real per-request handling never
        // hits this) — without forgetting it, the request below would
        // silently keep authenticating as $user regardless of the
        // Authorization header it's actually sent with.
        auth()->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($outsider))
            ->get(route('api.v1.tasks.attachments.show', $attachment->id))
            ->assertStatus(403);
    }
}
