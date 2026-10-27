<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Leaving the takeoff flow leaves a Resume button behind — on the Upload step too, as on web. */
class TakeoffFlowResumeTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
    }

    public function test_opening_upload_for_a_project_leaves_a_resume_at_the_upload_step(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $client = Client::create(['user_id' => $owner->id, 'name' => 'Apex']);
        $project = Project::create(['user_id' => $owner->id, 'client_id' => $client->id, 'name' => 'Phase 1', 'client' => 'Apex', 'status' => 'draft']);

        $this->withHeaders($this->headers($owner))->getJson('/api/v1/takeoff-flow')
            ->assertOk()->assertJsonPath('data.takeoffFlow', null);

        $this->withHeaders($this->headers($owner))->getJson("/api/v1/takeoffs/upload-options?project={$project->id}")->assertOk();

        $this->withHeaders($this->headers($owner))->getJson('/api/v1/takeoff-flow')
            ->assertOk()
            ->assertJsonPath('data.takeoffFlow.stage', 'Upload')
            ->assertJsonPath('data.takeoffFlow.projectId', $project->id)
            ->assertJsonPath('data.takeoffFlow.projectName', 'Phase 1');
    }

    public function test_the_reminder_can_be_put_away(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $client = Client::create(['user_id' => $owner->id, 'name' => 'Apex']);
        $project = Project::create(['user_id' => $owner->id, 'client_id' => $client->id, 'name' => 'Phase 1', 'client' => 'Apex', 'status' => 'draft']);
        $this->withHeaders($this->headers($owner))->getJson("/api/v1/takeoffs/upload-options?project={$project->id}")->assertOk();

        $this->withHeaders($this->headers($owner))->deleteJson('/api/v1/takeoff-flow')->assertOk();

        $this->withHeaders($this->headers($owner))->getJson('/api/v1/takeoff-flow')
            ->assertOk()->assertJsonPath('data.takeoffFlow', null);
    }
}
