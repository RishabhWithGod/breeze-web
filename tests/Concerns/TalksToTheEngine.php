<?php

namespace Tests\Concerns;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Project;
use App\Models\User;
use App\Services\Ai\AiTakeoffClient;
use App\Services\Ai\TakeoffOrchestrator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Shared plumbing for tests that run against the real AI engine.
 *
 * Nothing here is faked. The engine is called for real; the one concession to test
 * runtime is that a single analysis is reused across a test class (analysis takes
 * seconds per drawing), which still means every assertion is made against a genuine
 * `AnalysisResult`.
 *
 * Tests skip rather than fail when the engine is not running, so the suite stays
 * usable on a machine without it.
 */
trait TalksToTheEngine
{
    /**
     * The engine's response for the fixture drawing, fetched once per class.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $engineResponse = null;

    protected function fixturePath(): string
    {
        return base_path('tests/Fixtures/sample-drawing.pdf');
    }

    protected function skipUnlessEngineIsUp(): void
    {
        if (blank(config('ai.base_url'))) {
            $this->markTestSkipped('AI_API_BASE_URL is not set.');
        }

        if (! app(AiTakeoffClient::class)->health()['ok']) {
            $this->markTestSkipped(
                'The AI engine at '.config('ai.base_url').' is not reachable.'
            );
        }
    }

    /**
     * A real analysis of the fixture drawing, straight from the engine.
     *
     * @return array<string, mixed>
     */
    protected function engineResponse(): array
    {
        $this->skipUnlessEngineIsUp();

        if (self::$engineResponse === null) {
            self::$engineResponse = app(AiTakeoffClient::class)->analyse(
                $this->fixturePath(),
                'sample-drawing.pdf',
            );
        }

        return self::$engineResponse;
    }

    /** A project and upload holding the fixture drawing. */
    protected function openProject(User $user): Project
    {
        $project = $user->projects()->create([
            'name' => 'Fixture Drawing',
            'client' => 'Unassigned',
            'drawing_name' => 'sample-drawing.pdf',
            'status' => 'processing',
            'review_status' => 'none',
            'started_at' => now(),
        ]);

        $path = 'uploads/sample-drawing.pdf';
        Storage::disk(config('ai.storage.disk'))->put($path, file_get_contents($this->fixturePath()));

        $project->uploads()->create([
            'user_id' => $user->id,
            'name' => 'sample-drawing.pdf',
            'format' => 'PDF',
            'size_bytes' => filesize($this->fixturePath()),
            'path' => $path,
            'status' => 'processing',
        ]);

        return $project->refresh();
    }

    /**
     * Ingests the engine's real response, producing the rows under test.
     */
    protected function ingestRealAnalysis(User $user): AiResult
    {
        $project = $this->openProject($user);

        $aiJob = $project->aiJobs()->create([
            'upload_id' => $project->primaryUpload->id,
            'user_id' => $user->id,
            'status' => AiJob::STATUS_PROCESSING,
        ]);

        return app(TakeoffOrchestrator::class)->ingest($aiJob, $this->engineResponse());
    }

    /** The fixture drawing as an upload, for posting through the HTTP route. */
    protected function fixtureUpload(): UploadedFile
    {
        return new UploadedFile(
            $this->fixturePath(),
            'sample-drawing.pdf',
            'application/pdf',
            null,
            true,
        );
    }
}
