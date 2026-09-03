<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Upload;
use App\Models\User;
use App\Services\Ai\ArtefactStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Serving a drawing page to the review screen.
 *
 * Previews are rendered by a queued job, so on a worker that never ran the
 * screen used to have nothing to show and no way to recover: every Retry asked
 * for the same missing file and got the same 404. The rule now is that the
 * request renders them itself rather than being a dead end.
 */
class DrawingPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AiResult $result;

    private Upload $upload;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $project = $this->user->projects()->create([
            'name' => 'Harborview', 'client' => 'Harborview', 'status' => 'completed',
        ]);

        $store = app(ArtefactStore::class);
        $source = $store->directoryFor($project).'/sample-drawing.pdf';
        $store->disk()->put($source, file_get_contents(base_path('tests/Fixtures/sample-drawing.pdf')));

        $this->upload = $project->uploads()->create([
            'user_id' => $this->user->id,
            'name' => 'sample-drawing.pdf',
            'format' => 'PDF',
            'size_bytes' => 1024,
            'path' => $source,
            'status' => 'completed',
        ]);

        $aiJob = AiJob::create([
            'project_id' => $project->id,
            'upload_id' => $this->upload->id,
            'user_id' => $this->user->id,
            'status' => 'completed',
        ]);

        $this->result = AiResult::create([
            'ai_job_id' => $aiJob->id,
            'project_id' => $project->id,
            'upload_id' => $this->upload->id,
            'original_payload' => [],
        ]);
    }

    public function test_a_page_whose_preview_was_never_rendered_is_rendered_on_request(): void
    {
        $this->skipWithoutPdftoppm();

        // What a queued render that never ran leaves behind: paths on record,
        // nothing on the disk.
        $this->upload->update([
            'preview_paths' => ['takeoffs/1/previews/1/page-1.png'],
        ]);

        $this->actingAs($this->user)
            ->get(route('reviews.page', ['result' => $this->result->id, 'page' => 1]))
            ->assertOk();

        $store = app(ArtefactStore::class);
        $this->assertTrue(
            $store->exists($this->upload->refresh()->previewFor(1)),
            'The request should have rendered the previews it could not find.',
        );
    }

    public function test_a_second_request_is_served_off_the_disk(): void
    {
        $this->skipWithoutPdftoppm();

        $this->actingAs($this->user)
            ->get(route('reviews.page', ['result' => $this->result->id, 'page' => 1]))
            ->assertOk();

        // Already on disk, so nothing is shelled out to a second time.
        Process::fake();

        $this->actingAs($this->user)
            ->get(route('reviews.page', ['result' => $this->result->id, 'page' => 1]))
            ->assertOk();

        Process::assertNothingRan();
    }

    public function test_a_page_the_drawing_does_not_have_is_still_a_404(): void
    {
        $this->skipWithoutPdftoppm();

        // Rendering cannot invent a page 99; the honest answer is still "no".
        $this->actingAs($this->user)
            ->get(route('reviews.page', ['result' => $this->result->id, 'page' => 99]))
            ->assertNotFound();
    }

    public function test_a_result_with_no_drawing_at_all_is_a_404(): void
    {
        $this->result->update(['upload_id' => null]);

        $this->actingAs($this->user)
            ->get(route('reviews.page', ['result' => $this->result->id, 'page' => 1]))
            ->assertNotFound();
    }

    public function test_a_drawing_page_is_behind_authentication(): void
    {
        $this->get(route('reviews.page', ['result' => $this->result->id, 'page' => 1]))
            ->assertRedirect(route('login'));
    }

    /** The renderer shells out to poppler; without it there is nothing to test. */
    private function skipWithoutPdftoppm(): void
    {
        $binary = (string) config('ai.storage.pdftoppm');

        if (Process::run(['which', $binary])->failed()) {
            $this->markTestSkipped("{$binary} is not installed on this machine.");
        }
    }
}
