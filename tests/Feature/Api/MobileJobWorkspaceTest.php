<?php

namespace Tests\Feature\Api;

use App\Models\Document;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The job workspace's Messages, Additional Work and Documents — scoped to a
 * job the caller can access, with dollar figures kept from anyone but a manager.
 */
class MobileJobWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Job $job;

    private Foreman $journeymanRow;

    private User $journeyman;

    private User $foreman;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'Project Manager']);
        $team = Team::create(['name' => 'North']);

        $this->job = Job::create([
            'user_id' => $this->manager->id, 'team_id' => $team->id, 'name' => 'Riser rewire',
            'client' => 'Harborview', 'status' => 'in-progress', 'start_date' => now()->toDateString(),
        ]);

        $schedule = JobSchedule::create(['job_id' => $this->job->id, 'working_days' => [1, 2, 3, 4, 5]]);

        $this->foreman = $this->crewAccount('Dana', 'Foreman', 'foreman', $team->id);
        $this->journeyman = $this->crewAccount('Priya', 'Journeyman', 'journeyman', $team->id);
        $this->journeymanRow = $this->journeyman->foreman;

        $schedule->tasks()->create([
            'job_id' => $this->job->id, 'job_schedule_id' => $schedule->id, 'title' => 'Rough-in',
            'position' => 0, 'status' => 'pending',
            'foreman_id' => $this->journeymanRow->id, 'supervisor_id' => $this->foreman->foreman->id,
        ]);
    }

    private function crewAccount(string $name, string $webRole, string $registerRole, int $teamId): User
    {
        $user = User::factory()->create([
            'name' => $name, 'role' => $webRole,
            'registration_source' => User::SOURCE_MOBILE, 'status' => User::STATUS_ACTIVE,
        ]);
        $row = new Foreman(['name' => $name, 'initials' => substr($name, 0, 2), 'team_id' => $teamId, 'role' => $registerRole]);
        $row->user_id = $user->id;
        $row->save();

        return $user->refresh();
    }

    private function as(User $user): static
    {
        // Sanctum keeps the first authenticated user on the guard for the whole test.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken);
    }

    /* ------------------------------------------------------------ messages */

    public function test_messages_are_shared_and_unread_counts_are_per_person(): void
    {
        $this->as($this->journeyman)->postJson("/api/v1/jobs/{$this->job->id}/messages", ['body' => 'Panel is in'])->assertCreated();
        $this->as($this->journeyman)->postJson("/api/v1/jobs/{$this->job->id}/messages", ['body' => 'Photos soon'])->assertCreated();

        // The sender has nothing unread; the foreman has both.
        $this->as($this->journeyman)->getJson("/api/v1/jobs/{$this->job->id}")->assertJsonPath('data.unreadMessages', 0);
        $this->as($this->foreman)->getJson("/api/v1/jobs/{$this->job->id}")->assertJsonPath('data.unreadMessages', 2);

        // Opening the thread reads it, and shows who is who.
        $thread = $this->as($this->foreman)->getJson("/api/v1/jobs/{$this->job->id}/messages")->assertOk();
        $thread->assertJsonCount(2, 'data.messages');
        $thread->assertJsonPath('data.messages.0.body', 'Panel is in');
        $thread->assertJsonPath('data.messages.0.senderName', 'Priya');
        $thread->assertJsonPath('data.messages.0.isMine', false);
        $this->as($this->foreman)->getJson("/api/v1/jobs/{$this->job->id}")->assertJsonPath('data.unreadMessages', 0);

        // `after` returns only what is new.
        $this->as($this->foreman)->postJson("/api/v1/jobs/{$this->job->id}/messages", ['body' => 'Thanks'])->assertCreated();
        $lastSeen = $thread->json('data.messages.1.id');
        $this->as($this->journeyman)->getJson("/api/v1/jobs/{$this->job->id}/messages?after={$lastSeen}")
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.body', 'Thanks');
    }

    public function test_someone_not_staffed_on_the_job_cannot_read_or_post_messages(): void
    {
        $outsider = $this->crewAccount('Out', 'Journeyman', 'journeyman', Team::create(['name' => 'South'])->id);

        $this->as($outsider)->getJson("/api/v1/jobs/{$this->job->id}/messages")->assertForbidden();
        $this->as($outsider)->postJson("/api/v1/jobs/{$this->job->id}/messages", ['body' => 'hi'])->assertForbidden();
    }

    public function test_a_blank_message_is_rejected(): void
    {
        $this->as($this->journeyman)->postJson("/api/v1/jobs/{$this->job->id}/messages", ['body' => '   '])->assertStatus(422);
    }

    /* ------------------------------------------------------ additional work */

    public function test_a_foreman_raises_additional_work_without_seeing_or_setting_prices(): void
    {
        $response = $this->as($this->foreman)->postJson("/api/v1/jobs/{$this->job->id}/change-orders", [
            'description' => 'Extra conduit run',
            'reason' => 'Client moved the panel',
            'submit' => true,
            'lines' => [
                ['kind' => 'material', 'description' => 'EMT conduit', 'quantity' => 40, 'unit' => 'ft', 'unit_cost' => 999],
                ['kind' => 'labor', 'description' => 'Pull wire', 'quantity' => 3, 'unit' => 'hr'],
            ],
        ])->assertCreated();

        $response->assertJsonPath('data.status', 'submitted');
        $response->assertJsonPath('data.source', 'field');
        $response->assertJsonPath('data.lineCount', 2);
        $response->assertJsonMissingPath('data.amount');

        // A foreman cannot price it, whatever they send.
        $this->assertSame('0.00', (string) \App\Models\ChangeOrder::sole()->sell_total);

        $this->as($this->foreman)->getJson("/api/v1/jobs/{$this->job->id}/change-orders")
            ->assertJsonPath('data.canCreate', true)
            ->assertJsonCount(1, 'data.changeOrders')
            ->assertJsonMissingPath('data.changeOrders.0.amount');

        // A manager sees the money.
        $this->as($this->manager)->getJson("/api/v1/jobs/{$this->job->id}/change-orders")
            ->assertJsonPath('data.changeOrders.0.label', 'CO-001')
            ->assertJsonStructure(['data' => ['changeOrders' => [['amount', 'materialCost']]]]);
    }

    public function test_a_journeyman_can_read_but_not_raise_additional_work(): void
    {
        $this->as($this->journeyman)->postJson("/api/v1/jobs/{$this->job->id}/change-orders", [
            'description' => 'x', 'lines' => [['kind' => 'labor', 'description' => 'y', 'quantity' => 1]],
        ])->assertForbidden();

        $this->as($this->journeyman)->getJson("/api/v1/jobs/{$this->job->id}/change-orders")
            ->assertOk()->assertJsonPath('data.canCreate', false);
    }

    public function test_additional_work_needs_a_line(): void
    {
        $this->as($this->foreman)->postJson("/api/v1/jobs/{$this->job->id}/change-orders", ['description' => 'x'])
            ->assertStatus(422);
    }

    /* ------------------------------------------------ completing for the crew */

    public function test_a_foreman_or_manager_completing_a_journeymans_task_counts_for_that_journeyman(): void
    {
        $task = $this->job->tasks()->first();

        foreach ([$this->foreman, $this->manager] as $who) {
            $task->forceFill(['status' => 'pending', 'completion_pct' => 0, 'completed_at' => null])->save();

            $this->as($who)->postJson("/api/v1/tasks/{$task->id}/complete")->assertOk();

            // It is the task that is done, so it is done for the journeyman running it.
            $this->assertSame('completed', $task->fresh()->status);
            $this->assertSame($this->journeymanRow->id, $task->fresh()->foreman_id);

            $this->as($this->journeyman)->getJson("/api/v1/jobs/{$this->job->id}")
                ->assertOk()
                ->assertJsonPath('data.myTasksComplete', true);
            $this->as($this->journeyman)->getJson("/api/v1/tasks/{$task->id}")
                ->assertOk()
                ->assertJsonPath('data.status', 'completed');
        }
    }

    /* ----------------------------------------------------------- documents */

    public function test_documents_list_and_download_respect_private_files(): void
    {
        config(['documents.disk' => 'local']);
        Storage::fake('local');
        Storage::disk('local')->put('docs/plan.pdf', 'PDFDATA');
        Storage::disk('local')->put('docs/secret.pdf', 'SECRET');

        $shared = $this->document('Riser plan', 'docs/plan.pdf', 'team');
        $private = $this->document('Salary sheet', 'docs/secret.pdf', 'private');

        $list = $this->as($this->journeyman)->getJson("/api/v1/jobs/{$this->job->id}/documents")->assertOk();
        $list->assertJsonCount(1, 'data.documents');
        $list->assertJsonPath('data.documents.0.name', 'Riser plan');

        $this->as($this->journeyman)->get("/api/v1/jobs/{$this->job->id}/documents/{$shared->id}/download")
            ->assertOk()->assertDownload('plan.pdf');
        $this->as($this->journeyman)->get("/api/v1/jobs/{$this->job->id}/documents/{$private->id}/download")
            ->assertNotFound();

        $this->as($this->journeyman)->getJson("/api/v1/jobs/{$this->job->id}")
            ->assertJsonPath('data.documentsCount', 1);
    }

    private function document(string $name, string $path, string $visibility): Document
    {
        return Document::create([
            'name' => $name, 'original_filename' => basename($path), 'storage_path' => $path,
            'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => 7,
            'document_type' => 'Blueprint', 'job_id' => $this->job->id, 'uploaded_by' => $this->manager->id,
            'version' => 1, 'is_latest' => true, 'is_archived' => false, 'visibility' => $visibility,
        ]);
    }
}
