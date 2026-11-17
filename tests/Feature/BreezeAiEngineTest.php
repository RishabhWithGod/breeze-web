<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\User;
use App\Services\Ai\AiApiException;
use App\Services\Ai\AiTakeoffClient;
use App\Services\Ai\TakeoffOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\TalksToTheEngine;
use Tests\TestCase;

/**
 * The breeze-ai engine contract: async submit → poll status → fetch result, and
 * a real stored response carried through ingest. The engine is faked at the HTTP
 * edge; the response body is a genuine engine result (tests/Fixtures).
 */
class BreezeAiEngineTest extends TestCase
{
    use RefreshDatabase;
    use TalksToTheEngine;

    private const BASE = 'http://engine.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.base_url' => self::BASE,
            'ai.polling.interval_seconds' => 0.2,
            'static_takeoff.enabled' => false,
        ]);
    }

    private function realResponse(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/breeze-ai-response.json')), true);
    }

    public function test_analyse_submits_polls_and_returns_the_result(): void
    {
        $result = $this->realResponse();

        Http::fake([
            self::BASE.'/api/v1/electrical/extract' => Http::response(['job_id' => 'job-1', 'status' => 'queued'], 202),
            self::BASE.'/api/v1/electrical/extract/job-1/status' => Http::sequence()
                ->push(['job_id' => 'job-1', 'status' => 'processing'])
                ->push(['job_id' => 'job-1', 'status' => 'completed']),
            self::BASE.'/api/v1/electrical/extract/job-1/result' => Http::response($result),
        ]);

        $payload = app(AiTakeoffClient::class)->analyse($this->fixturePath(), 'sample-drawing.pdf');

        $this->assertSame($result['components'][0]['name'], $payload['components'][0]['name']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/electrical/extract')
            && $r->isMultipart());
        Http::assertSentCount(4);
    }

    public function test_a_failed_job_surfaces_the_engines_message(): void
    {
        Http::fake([
            self::BASE.'/api/v1/electrical/extract' => Http::response(['job_id' => 'job-2'], 202),
            self::BASE.'/api/v1/electrical/extract/job-2/status' => Http::response([
                'job_id' => 'job-2', 'status' => 'failed', 'error_message' => 'Breeze AI could not read this drawing.',
            ]),
        ]);

        $this->expectException(AiApiException::class);
        $this->expectExceptionMessage('Breeze AI could not read this drawing.');

        app(AiTakeoffClient::class)->analyse($this->fixturePath(), 'sample-drawing.pdf');
    }

    public function test_a_real_extraction_ingests_into_reviewable_rows(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        Http::fake([
            self::BASE.'/api/v1/electrical/symbols/*/image' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            '*' => Http::response(['status' => 'healthy']),
        ]);

        $user = User::factory()->create();
        $project = $this->openProject($user);
        $aiJob = $project->aiJobs()->create([
            'upload_id' => $project->primaryUpload->id,
            'user_id' => $user->id,
            'status' => AiJob::STATUS_PROCESSING,
        ]);

        $payload = $this->realResponse();
        $result = app(TakeoffOrchestrator::class)->ingest($aiJob, $payload);

        $this->assertEquals($payload, $result->original_payload, 'the engine response is stored verbatim');
        $this->assertGreaterThan(0, $result->reviews()->count());
        $this->assertSame(count($payload['bill_of_quantities']), $result->boqLines()->count());
        $this->assertSame($payload['document']['total_pages'], $result->page_count);
        $this->assertArrayHasKey(1, $result->pageDimensions());

        // Each card the engine tied to a legend row carries that symbol's real image.
        $withImage = $result->reviews()->whereNotNull('crop_path')->get();
        $this->assertGreaterThan(0, $withImage->count());
        $this->assertTrue(
            $withImage->every(fn ($review) => app(\App\Services\Ai\ArtefactStore::class)->exists($review->crop_path)),
        );

        $this->assertSame(
            $result->reviews()->count(),
            $result->reviews()->pluck('external_id')->unique()->count(),
        );
    }
}
