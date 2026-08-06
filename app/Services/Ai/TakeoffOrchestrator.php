<?php

namespace App\Services\Ai;

use App\Events\TakeoffFailed;
use App\Events\TakeoffProcessed;
use App\Jobs\BackfillTakeoffCrops;
use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Project;
use App\Models\SymbolReview;
use App\Models\Upload;
use App\Models\User;
use App\Services\Takeoff\EstimateBuilder;
use App\Services\Takeoff\JobFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drives a takeoff run from submission to a reviewable result.
 *
 * The engine's `POST /api/upload` is synchronous — it returns the whole
 * AnalysisResult — so a run is: `open()` records it, `analyse()` posts the drawing
 * and ingests what comes back. Progress is coarse by necessity (the engine reports
 * no intermediate percentage), and the browser still reads it from Laravel.
 *
 * Nothing here invents data: a response with no usable content fails the run.
 */
class TakeoffOrchestrator
{
    public function __construct(
        private readonly AiTakeoffClient $client,
        private readonly AiResponseNormaliser $normaliser,
        private readonly ArtefactStore $store,
        private readonly LifecycleReader $lifecycle,
        private readonly JobFactory $jobs,
        private readonly EstimateBuilder $estimates,
        private readonly RunProfiler $profiler,
    ) {}

    public function configured(): bool
    {
        return $this->client->isConfigured();
    }

    /** @return array{ok: bool, detail: array<string, mixed>|null} */
    public function health(): array
    {
        return $this->client->health();
    }

    /** Records a run for the project's drawing. */
    public function open(Project $project, Upload $upload, User $user): AiJob
    {
        return $project->aiJobs()->create([
            'upload_id' => $upload->id,
            'user_id' => $user->id,
            'status' => AiJob::STATUS_QUEUED,
            'progress' => 0,
            'stage' => 'queued',
            'stage_label' => 'Queued',
            'queued_at' => now(),
            'request_meta' => [
                'drawing' => $upload->name,
                'size_bytes' => $upload->size_bytes,
                'notes' => $project->notes,
            ],
        ]);
    }

    /**
     * Posts the drawing to the engine and ingests the analysis it returns.
     */
    public function analyse(AiJob $aiJob): AiResult
    {
        $this->profiler->start();

        // The gap between dispatch and a worker starting. This is the stage that
        // grows to minutes when no worker is running, and it is invisible from
        // inside the run itself.
        if ($aiJob->queued_at) {
            $this->profiler->record(
                'queue_wait',
                max(0, (microtime(true) - $aiJob->queued_at->getTimestamp()) * 1000),
            );
        }

        $upload = $aiJob->upload;

        if (! $upload || ! $this->store->exists($upload->path)) {
            throw AiApiException::unusablePayload('the stored drawing is missing.');
        }

        $aiJob->update([
            'status' => AiJob::STATUS_UPLOADING,
            'progress' => 10,
            'stage' => 'uploading',
            'stage_label' => 'Uploading Drawing',
            'submitted_at' => now(),
        ]);

        /*
         * Page previews are not rendered here. Nothing in the analysis reads one —
         * they are for the review and drawing screens — so `pdftoppm` used to cost
         * the run about a second for nothing. `RenderDrawingPreviews` is queued when
         * the drawing is submitted and renders alongside this call instead.
         */
        $aiJob->update([
            'status' => AiJob::STATUS_PROCESSING,
            'progress' => 35,
            'stage' => 'analysing',
            'stage_label' => 'Analysing Drawing',
        ]);

        $payload = $this->profiler->measure('python_analyse', fn () => $this->client->analyse(
            $this->store->absolutePath($upload->path),
            $upload->name,
        ));

        $aiJob->update([
            'progress' => 85,
            'stage' => 'ingesting',
            'stage_label' => 'Reading Results',
            'last_status_payload' => [
                'pipeline_status' => $payload['pipeline_status'] ?? null,
                'processing_time' => $payload['processing_time'] ?? null,
                'pages' => $payload['pages'] ?? null,
            ],
        ]);

        return $this->ingest($aiJob, $payload);
    }

    /**
     * Turns an AnalysisResult into reviewable rows.
     *
     * The response is stored verbatim before anything is derived from it, so the
     * audit record survives even if normalisation later fails.
     *
     * @param  array<string, mixed>  $payload
     */
    public function ingest(AiJob $aiJob, array $payload): AiResult
    {
        if (! $this->profiler->started()) {
            $this->profiler->start();
        }

        $normalised = $this->profiler->measure(
            'normalise',
            fn () => $this->normaliser->normalise($payload),
        );

        $project = $aiJob->project;
        // Asked once, outside the transaction — the engine reports its build
        // through /api/health rather than per run.
        $engineVersion = $this->profiler->measure(
            'engine_health',
            fn () => $this->client->health()['detail']['version'] ?? null,
        );

        $result = $this->profiler->measure('db_transaction', fn () => DB::transaction(function () use ($aiJob, $payload, $normalised, $project, $engineVersion) {
            $result = AiResult::create([
                'ai_job_id' => $aiJob->id,
                'project_id' => $project->id,
                'upload_id' => $aiJob->upload_id,
                'project_name' => $normalised['project_name'],
                'original_payload' => $payload,
                'model_version' => $engineVersion,
                'page_count' => $normalised['pages'],
                'detection_count' => count($normalised['cards']),
                'overall_confidence' => $normalised['confidence'],
                'processing_time' => $normalised['processing_time'],
                'pipeline_status' => $normalised['pipeline_status'],
                'warnings' => $normalised['warnings'],
                'symbol_counts' => $normalised['symbol_counts'],
                'ai_estimate' => $normalised['estimate'],
                'review_status' => AiResult::REVIEW_PENDING,
                'received_at' => now(),
            ]);

            $this->profiler->measure('cards_write', fn () => $this->writeCards($result, $normalised['cards']));
            $this->profiler->measure('sections_write', fn () => $this->writeSections($result, $normalised));

            $result->recordHistory(
                'ai_response_received',
                count($normalised['cards']).' symbols returned by the AI engine'
                    .($normalised['warnings'] === [] ? '' : ', with '.count($normalised['warnings']).' warnings'),
                meta: [
                    'pages' => $normalised['pages'],
                    'item_total' => $normalised['item_total'],
                    'processing_time' => $normalised['processing_time'],
                    'pipeline_status' => $normalised['pipeline_status'],
                ],
            );

            $project->update([
                'status' => 'completed',
                'review_status' => AiResult::REVIEW_PENDING,
                'name' => $normalised['project_name'] ?: $project->name,
                'items_count' => $normalised['item_total'],
                'page_count' => $normalised['pages'],
                'overall_confidence' => $normalised['confidence'],
                'completed_at' => now(),
            ]);

            $project->uploads()->update(['status' => 'completed']);

            // Deliberately not marked succeeded yet: the crops attach first, so the
            // review screen never opens on a half-built run.
            $aiJob->update([
                'progress' => 92,
                'stage' => 'crops',
                'stage_label' => 'Attaching Crops',
            ]);

            return $result;
        }));

        /*
         * Filed after the transaction commits, not inside it. Encoding a multi-megabyte
         * response and pushing it to disk is slow, and doing it mid-transaction held the
         * row lock for the whole of it while every poll queued up behind. The response is
         * already safe in `original_payload`; this is the on-disk copy of the same thing,
         * so a crash between the two costs a file, never the audit record.
         */
        $this->profiler->measure('artefact_write', fn () => $result->updateQuietly([
            'original_path' => $this->store->putOriginalResponse($result, $payload),
        ]));

        $this->profiler->measure('lifecycle_enrich', fn () => $this->enrichFromLifecycle($result));

        $aiJob->update([
            'status' => AiJob::STATUS_SUCCEEDED,
            'progress' => 100,
            'stage' => 'completed',
            'stage_label' => 'Completed',
            'completed_at' => now(),
        ]);

        $this->profiler->measure('handoff', fn () => $this->raiseProvisionalHandoff($result));

        $this->profiler->measure('events', fn () => TakeoffProcessed::dispatch($result));

        $this->profiler->persist($aiJob, [
            'engine_reported_s' => $normalised['processing_time'],
            'cards' => count($normalised['cards']),
        ]);

        return $result;
    }

    /**
     * Raises the job and its estimate from the AI response, as soon as it lands.
     *
     * The drawing has been read, so the work it describes exists — both modules show
     * it immediately instead of waiting on a reviewer. The numbers are the engine's
     * until the review is signed off, which rewrites these same two records rather
     * than adding more; both are marked as pending review meanwhile.
     *
     * A failure here never fails the analysis: the response is stored and reviewable,
     * so the run is a success even if pricing could not be raised yet. It is logged
     * and audited, never swallowed.
     */
    private function raiseProvisionalHandoff(AiResult $result): void
    {
        if (! config('ai.auto_handoff')) {
            return;
        }

        $owner = $result->project->user;

        if ($owner === null) {
            return;
        }

        try {
            DB::transaction(function () use ($result, $owner) {
                $job = $this->jobs->fromEngineResponse($result, $owner);
                $this->estimates->fromEngineResponse($result->refresh(), $owner, $job);
            });
        } catch (Throwable $e) {
            Log::warning('Provisional job and estimate could not be raised', [
                'ai_result_id' => $result->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $result->recordHistory(
                'handoff_deferred',
                'The job and estimate could not be raised from the AI response: '.$e->getMessage(),
                meta: ['exception' => $e::class],
            );
        }
    }

    public function cancel(AiJob $aiJob): void
    {
        // The engine exposes no cancel for a synchronous analysis; the run is
        // abandoned locally and the in-flight request simply finishes unused.
        $aiJob->update([
            'status' => AiJob::STATUS_CANCELLED,
            'stage' => 'cancelled',
            'stage_label' => 'Cancelled',
            'completed_at' => now(),
        ]);

        $aiJob->project->update(['status' => 'failed']);
        $aiJob->project->uploads()->update(['status' => 'failed']);
    }

    public function fail(AiJob $aiJob, string $message): void
    {
        $aiJob->markFailed($message);
        $aiJob->project->update(['status' => 'failed']);
        $aiJob->project->uploads()->update(['status' => 'failed']);

        TakeoffFailed::dispatch($aiJob, $message);
    }

    /* ------------------------------------------------------------- internals */

    /**
     * One review row per symbol type, plus one per needs-review observation.
     *
     * Inserted in one statement rather than one per card. A busy drawing returns
     * several hundred, and a row at a time meant several hundred round trips inside
     * the transaction that also holds the write lock.
     *
     * @param  list<array<string, mixed>>  $cards
     */
    private function writeCards(AiResult $result, array $cards): void
    {
        $rows = array_map(fn (array $card) => [
            'project_id' => $result->project_id,
            'external_id' => $card['external_id'],
            'origin' => $card['origin'],
            'ai_category' => $card['ai_category'],
            'reason' => $card['reason'] ?? null,
            'ai_name' => $card['name'],
            'name' => $card['name'],
            'page' => $card['page'] ?? 1,
            'confidence' => $card['confidence'],
            'source_template' => $card['sources']['template'],
            'source_vector' => $card['sources']['vector'],
            'source_vision' => $card['sources']['vision'],
            'source_ocr' => $card['sources']['ocr'],
            'evidence' => $card['evidence'],
            'detection_source' => $card['detection_source'] ?? null,
            'is_known' => $card['is_known'],
            'ai_count' => $card['count'],
            'final_count' => $card['count'],
            'status' => $card['status'],
            'image_id' => $card['image_id'] ?? null,
            'image_path' => $card['image_path'] ?? null,
            'reviewed_at' => null,
            'position' => $card['position'],
        ], $cards);

        $this->insertMany($result->reviews(), $rows);
    }

    /**
     * The structured sections of the response: panel schedules, equipment, wire
     * sizes, circuits and the engine's own bill of quantities.
     *
     * @param  array<string, mixed>  $normalised
     */
    private function writeSections(AiResult $result, array $normalised): void
    {
        $this->insertMany($result->panelSchedules(), $normalised['panel_schedules']);
        $this->insertMany($result->equipment(), $normalised['equipment']);
        $this->insertMany($result->wireSizes(), $normalised['wire_sizes']);
        $this->insertMany($result->circuits(), $normalised['circuits']);
        $this->insertMany($result->boqLines(), $normalised['boq']);
    }

    /**
     * Bulk-inserts rows into a relation without giving up Eloquent's casts.
     *
     * `insert()` writes raw values, so an array-cast column would otherwise reach
     * the database as the string "Array". Each row is put through a real model
     * first, which applies the casts and the relation's foreign key, and the
     * DB-ready attributes are what actually get inserted.
     *
     * @param  HasMany<covariant Model, covariant Model>  $relation
     * @param  iterable<array<string, mixed>>  $rows
     */
    private function insertMany(HasMany $relation, iterable $rows): void
    {
        $now = now();
        $prepared = [];

        foreach ($rows as $row) {
            $model = $relation->make($row);
            $model->forceFill(['created_at' => $now, 'updated_at' => $now]);

            $prepared[] = $model->getAttributes();
        }

        if ($prepared === []) {
            return;
        }

        // Chunked so a drawing with thousands of detections cannot build a single
        // statement past the server's packet limit.
        foreach (array_chunk($prepared, 500) as $chunk) {
            $relation->insert($chunk);
        }
    }

    /**
     * Adds crop images, bounding boxes and stage trails from the engine's
     * lifecycle endpoint. Decoration: a failure must never lose a finished run.
     */
    private function enrichFromLifecycle(AiResult $result): void
    {
        if (! $this->lifecycle->enabled()) {
            return;
        }

        try {
            $run = $this->lifecycle->resolve($result->project_name ?: $result->project->name);

            if ($run === null) {
                Log::info('No lifecycle run matched this analysis; cards will have no crop images.', [
                    'ai_result_id' => $result->id,
                    'project_name' => $result->project_name,
                ]);

                return;
            }

            $enriched = $this->lifecycle->attach($result, $run['lifecycle']);

            $result->recordHistory(
                'lifecycle_attached',
                "Crop detail attached from engine run {$run['run_id']} ({$enriched} symbols)",
                meta: ['run_id' => $run['run_id'], 'enriched' => $enriched],
            );

            // Any crop the engine did not hand over is fetched later, off the
            // request path, rather than on demand while a page renders.
            $missing = $result->reviews()
                ->whereNull('crop_path')
                ->whereNotNull('image_path')
                ->exists();

            if ($missing) {
                BackfillTakeoffCrops::dispatch($result->id);
            }
        } catch (Throwable $e) {
            Log::warning('Lifecycle detail could not be attached', [
                'ai_result_id' => $result->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Review counters after ingest, for the "N need review" summary. */
    public function tallyFor(AiResult $result): array
    {
        return [
            'symbols' => $result->reviews()->where('origin', SymbolReview::ORIGIN_SYMBOL)->count(),
            'needsReview' => $result->reviews()->where('origin', SymbolReview::ORIGIN_NEEDS_REVIEW)->count(),
        ];
    }
}
