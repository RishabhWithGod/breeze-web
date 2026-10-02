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

    public function test_a_foreman_raises_priced_additional_work_and_opens_its_detail(): void
    {
        $response = $this->as($this->foreman)->postJson("/api/v1/jobs/{$this->job->id}/change-orders", [
            'description' => 'Extra conduit run',
            'reason' => 'Client moved the panel',
            'submit' => true,
            'lines' => [
                ['kind' => 'material', 'description' => 'EMT conduit', 'quantity' => 40, 'unit_cost' => 2.5],
                ['kind' => 'labor', 'description' => 'Pull wire', 'quantity' => 3, 'unit' => 'hr', 'unit_cost' => 80],
            ],
        ])->assertCreated();

        $response->assertJsonPath('data.status', 'submitted');
        $response->assertJsonPath('data.source', 'field');
        $response->assertJsonPath('data.lineCount', 2);
        $response->assertJsonPath('data.amount', 340);

        $co = \App\Models\ChangeOrder::sole();
        $this->assertSame('340.00', (string) $co->sell_total);

        $this->as($this->foreman)->getJson("/api/v1/jobs/{$this->job->id}/change-orders/{$co->id}")
            ->assertOk()
            ->assertJsonPath('data.laborCost', 240)
            ->assertJsonPath('data.materialCost', 100)
            ->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.lines.0.total', 100)
            ->assertJsonPath('data.history.0.type', 'submitted')
            ->assertJsonPath('data.canAttach', true);

        $file = \Illuminate\Http\UploadedFile::fake()->image('panel.jpg');
        $this->as($this->foreman)->post("/api/v1/jobs/{$this->job->id}/change-orders/{$co->id}/attachments", ['attachments' => [$file]], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(1, 'data.attachments');

        $this->as($this->foreman)->getJson("/api/v1/jobs/{$this->job->id}/change-orders")
            ->assertJsonPath('data.canCreate', true)
            ->assertJsonCount(1, 'data.changeOrders');

        // The crew read it but never see money.
        $this->as($this->journeyman)->getJson("/api/v1/jobs/{$this->job->id}/change-orders/{$co->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.amount')
            ->assertJsonMissingPath('data.lines.0.unitCost')
            ->assertJsonPath('data.canAttach', false);
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

    public function test_a_change_order_carries_its_reason_and_customer_request_and_is_saved_once_per_client_key(): void
    {
        $payload = [
            'description' => 'Move the panel', 'reason_code' => 'scope_change', 'customer_requested' => true,
            'client_key' => 'co-123', 'submit' => true,
            'lines' => [['kind' => 'labor', 'description' => 'Labor', 'quantity' => 4, 'unit' => 'hr', 'unit_cost' => 70]],
        ];

        $first = $this->as($this->foreman)->postJson("/api/v1/jobs/{$this->job->id}/change-orders", $payload)->assertCreated();
        $first->assertJsonPath('data.reasonCode', 'scope_change')->assertJsonPath('data.reasonLabel', 'Scope change')->assertJsonPath('data.customerRequested', true);

        // The same request replayed (it was queued offline) does not make a second one.
        $this->app['auth']->forgetGuards();
        $this->as($this->foreman)->postJson("/api/v1/jobs/{$this->job->id}/change-orders", $payload)->assertOk();
        $this->assertSame(1, \App\Models\ChangeOrder::count());

        // Evidence that arrives later finds it by that key.
        $this->app['auth']->forgetGuards();
        $this->as($this->foreman)->post('/api/v1/change-orders/by-key/co-123/attachments', ['attachments' => [\Illuminate\Http\UploadedFile::fake()->image('a.jpg')]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonCount(1, 'data.attachments');

        $this->app['auth']->forgetGuards();
        $this->as($this->foreman)->getJson('/api/v1/change-orders')
            ->assertOk()
            ->assertJsonCount(1, 'data.changeOrders')
            ->assertJsonPath('data.changeOrders.0.jobName', $this->job->name)
            ->assertJsonPath('data.canCreate', true)
            ->assertJsonStructure(['data' => ['jobs' => [['id', 'name']], 'reasons' => [['value', 'label']]]]);

        $this->app['auth']->forgetGuards();
        $this->as($this->journeyman)->getJson('/api/v1/change-orders')->assertForbidden();
    }

    public function test_a_message_written_offline_lands_once_however_often_it_is_sent(): void
    {
        $body = ['body' => 'On my way', 'client_key' => 'msg-1'];

        $this->as($this->journeyman)->postJson("/api/v1/jobs/{$this->job->id}/messages", $body)->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->as($this->journeyman)->postJson("/api/v1/jobs/{$this->job->id}/messages", $body)->assertOk();

        $this->assertSame(1, \App\Models\JobMessage::count());
    }
}
