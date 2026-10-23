<?php

namespace Tests\Feature\Api;

use App\Models\CompanyProfile;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A takeoff's paperwork, as mobile sees it — web's Documents module:
 * favorites, archive / restore (archived ones stay listed), versions and
 * history, sharing, and the file itself.
 */
class MobileDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->manager = User::factory()->create(['role' => 'Project Manager']);
        $this->project = Project::create(['user_id' => $this->manager->id, 'name' => 'Data Hall', 'client' => 'Harborview', 'status' => 'draft']);
    }

    private function auth(?User $user = null): array
    {
        return ['Authorization' => 'Bearer '.($user ?? $this->manager)->createToken('t')->plainTextToken];
    }

    private function upload(string $name = 'plans.pdf'): int
    {
        return $this->withHeaders($this->auth())->post("/api/v1/takeoffs/{$this->project->id}/documents", [
            'file' => UploadedFile::fake()->create($name, 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    public function test_the_list_carries_flags_and_keeps_archived_documents_marked(): void
    {
        $id = $this->upload();
        $this->withHeaders($this->auth())->postJson("/api/v1/documents/{$id}/archive")->assertOk();

        $response = $this->withHeaders($this->auth())->getJson("/api/v1/takeoffs/{$this->project->id}/documents")->assertOk();

        $row = $response->json('data.documents.0');
        $this->assertTrue($row['isArchived']);
        $this->assertTrue($row['canDelete']);
        $this->assertSame('v1.0 (Latest)', $row['versionLabel']);
        $this->assertIsArray($response->json('data.shareableUsers'));

        $this->withHeaders($this->auth())->postJson("/api/v1/documents/{$id}/restore")->assertOk();
        $this->assertFalse(Document::find($id)->is_archived);
    }

    public function test_a_document_can_be_favorited_and_unfavorited(): void
    {
        $id = $this->upload();

        $this->withHeaders($this->auth())->postJson("/api/v1/documents/{$id}/favorite")
            ->assertOk()->assertJsonPath('data.isFavorite', true);
        $this->withHeaders($this->auth())->postJson("/api/v1/documents/{$id}/favorite")
            ->assertOk()->assertJsonPath('data.isFavorite', false);
    }

    public function test_a_new_version_becomes_the_latest_and_history_lists_every_one(): void
    {
        $id = $this->upload();

        $version = $this->withHeaders($this->auth())->post("/api/v1/documents/{$id}/versions", [
            'file' => UploadedFile::fake()->create('plans-v2.pdf', 120, 'application/pdf'),
            'description' => 'Revised',
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertTrue($version->json('data.isLatest'));
        $history = $this->withHeaders($this->auth())->getJson("/api/v1/documents/{$version->json('data.id')}/history")->assertOk();
        $this->assertCount(2, $history->json('data.versions'));
    }

    public function test_a_document_can_be_shared_and_its_file_streamed(): void
    {
        $id = $this->upload();
        $other = User::factory()->create(['role' => 'Electrician']);

        $this->withHeaders($this->auth())->postJson("/api/v1/documents/{$id}/share", ['user_id' => $other->id, 'permission' => 'view'])->assertOk();
        $this->assertDatabaseHas('document_shares', ['document_id' => $id, 'shared_with_user_id' => $other->id]);

        $this->withHeaders($this->auth())->postJson("/api/v1/documents/{$id}/share", ['user_id' => $this->manager->id, 'permission' => 'view'])
            ->assertStatus(422);

        $file = $this->withHeaders($this->auth())->get("/api/v1/documents/{$id}/file");
        $file->assertOk();
        $this->assertStringContainsString('inline', $file->headers->get('Content-Disposition'));
    }

    public function test_someone_in_another_company_cannot_touch_the_document(): void
    {
        $id = $this->upload();

        $stranger = User::factory()->create(['role' => 'Project Manager']);
        $company = CompanyProfile::create([
            'user_id' => $stranger->id, 'name' => 'Other Co', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'other@x.test', 'timezone' => 'America/Chicago',
        ]);
        $stranger->forceFill(['company_id' => $company->id])->save();
        $auth = $this->auth($stranger->fresh());

        // The guard remembers the manager from the upload above.
        $this->app['auth']->forgetGuards();

        $this->withHeaders($auth)->get("/api/v1/documents/{$id}/file")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withHeaders($auth)->postJson("/api/v1/documents/{$id}/favorite")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withHeaders($auth)->postJson("/api/v1/documents/{$id}/archive")->assertForbidden();
    }
}
