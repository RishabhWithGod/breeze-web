<?php

namespace App\Services\Takeoff;

use App\Models\AiResult;
use App\Models\Job;
use App\Models\SymbolReview;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The job behind a takeoff, at both stages of its life.
 *
 * A drawing that comes back from the engine gets its job straight away, built from
 * the AI response, so the Jobs module reflects the drawing the moment it is read —
 * `fromEngineResponse()`. Signing off the review then brings the *same* job up to
 * the reviewed numbers — `fromFinalJson()`. There is only ever one job per takeoff.
 *
 * Which stage a job is at is recorded on it (`metadata.reviewed`), because the
 * difference matters: pre-review counts are the machine's opinion, post-review
 * counts are a person's.
 */
class JobFactory
{
    public function __construct(private readonly BillOfQuantities $boq) {}

    /**
     * The provisional job, from the engine's response.
     *
     * Counts are the engine's own, so they can move when the review is signed off.
     */
    public function fromEngineResponse(AiResult $result, User $user): Job
    {
        $payload = $this->engineViewOf($result);

        return DB::transaction(function () use ($result, $payload, $user) {
            if ($result->workJob) {
                return $this->refresh($result->workJob, $payload, reviewed: false);
            }

            $job = $this->write($result, $payload, [], reviewed: false);

            $result->recordHistory(
                'job_created',
                "Job “{$job->name}” created from the AI response, pending review",
                to: $job->name,
                meta: ['job_id' => $job->id, 'user_id' => $user->id, 'reviewed' => false],
            );

            return $job;
        });
    }

    /**
     * The reviewed job, from final_response.json.
     *
     * Updates the job raised at analysis time rather than adding a second one; the
     * reviewed counts and bill of quantities replace the engine's.
     *
     * @param  array<string, mixed>  $attributes  Overrides from the create form.
     */
    public function fromFinalJson(AiResult $result, User $user, array $attributes = []): Job
    {
        $payload = $result->final_payload;

        if (blank($payload)) {
            throw new RuntimeException('This takeoff has no final JSON yet — generate it first.');
        }

        return DB::transaction(function () use ($result, $payload, $attributes, $user) {
            $existing = $result->workJob;

            if ($existing) {
                $job = $this->refresh($existing, $payload, reviewed: true);

                $result->project->update(['status' => 'converted']);
                $result->recordHistory(
                    'job_updated',
                    "Job “{$job->name}” updated to the reviewed counts",
                    to: $job->name,
                    meta: ['job_id' => $job->id, 'user_id' => $user->id, 'reviewed' => true],
                );

                return $job;
            }

            $job = $this->write($result, $payload, $attributes, reviewed: true);

            $result->project->update(['status' => 'converted']);
            $result->recordHistory(
                'job_created',
                "Job “{$job->name}” created from the final JSON",
                to: $job->name,
                meta: ['job_id' => $job->id, 'user_id' => $user->id, 'reviewed' => true],
            );

            return $job;
        });
    }

    /* --------------------------------------------------------------- writing */

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $attributes
     */
    private function write(AiResult $result, array $payload, array $attributes, bool $reviewed): Job
    {
        $project = $result->project;

        $job = Job::create([
            'project_id' => $project->id,
            'ai_result_id' => $result->id,
            'name' => $attributes['name'] ?? $project->name,
            'client' => $attributes['client'] ?? ($project->client === 'Unassigned' ? null : $project->client),
            'location' => $attributes['location'] ?? null,
            'description' => $attributes['description'] ?? $this->describe($payload, $reviewed),
            'job_type' => $attributes['job_type'] ?? 'commercial',
            'status' => 'planning',
            'foreman_id' => $attributes['foreman_id'] ?? null,
            'start_date' => $attributes['start_date'] ?? null,
            'end_date' => $attributes['end_date'] ?? null,
            ...$this->takeoffFields($result, $payload, $reviewed),
            'budget' => $attributes['budget'] ?? $this->budget($payload),
        ]);

        $job->recordInitialStatus();
        $job->recordActivity(
            'created',
            $reviewed
                ? "Job created from the reviewed takeoff for “{$project->name}”"
                : "Job created from the AI takeoff for “{$project->name}”, pending review",
            [
                'project_id' => $project->id,
                'ai_result_id' => $result->id,
                'items' => Arr::get($payload, 'metadata.final_item_total'),
                'reviewed' => $reviewed,
            ],
        );

        $result->update(['work_job_id' => $job->id]);
        $result->setRelation('workJob', $job);

        return $job;
    }

    /**
     * Brings an existing job up to a newer view of the same drawing.
     *
     * Only the takeoff-derived fields move. Anything a person has since set — name,
     * client, dates, foreman, status, an edited budget — is left alone, because the
     * job is theirs once it exists.
     *
     * @param  array<string, mixed>  $payload
     */
    private function refresh(Job $job, array $payload, bool $reviewed): Job
    {
        $wasReviewed = (bool) Arr::get($job->metadata, 'reviewed', false);
        $before = (int) collect($job->symbol_counts ?? [])->sum();
        $after = (int) collect(Arr::get($payload, 'final_counts', []))->sum();

        $job->update($this->takeoffFields($job->aiResult, $payload, $reviewed));

        // The budget follows the takeoff only while nobody has overridden it.
        if (! $wasReviewed && $reviewed) {
            $job->update([
                'budget' => $this->budget($payload),
                'description' => $this->describe($payload, $reviewed),
            ]);

            $job->recordActivity(
                'updated',
                "Takeoff signed off: counts moved from {$before} to {$after} items",
                ['items_before' => $before, 'items_after' => $after, 'reviewed' => true],
            );
        }

        return $job->refresh();
    }

    /**
     * The fields a job carries from its takeoff.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function takeoffFields(?AiResult $result, array $payload, bool $reviewed): array
    {
        $boq = Arr::get($payload, 'boq', []);

        return [
            'symbol_counts' => Arr::get($payload, 'final_counts', []),
            'boq' => $boq,
            'metadata' => [
                'source' => 'ai-takeoff',
                // The single fact that says whether these numbers are a person's.
                'reviewed' => $reviewed,
                'drawing' => Arr::get($payload, 'drawing'),
                'project_name' => Arr::get($payload, 'engine.project_name'),
                'drawing_name' => $result?->project?->drawing_name,
                'total_symbols' => Arr::get($payload, 'metadata.final_item_total'),
                'symbol_types' => count(Arr::get($payload, 'final_counts', [])),
                'final_response' => $result?->final_path,
                'original_response' => $result?->original_path,
                'engine_version' => Arr::get($payload, 'metadata.engine_version'),
                'engine_run_id' => Arr::get($payload, 'engine.run_id'),
                'processing_time' => Arr::get($payload, 'engine.processing_time'),
                'pipeline_status' => Arr::get($payload, 'engine.pipeline_status', []),
                'warnings' => Arr::get($payload, 'engine.warnings', []),
                // The engine's own priced view, kept beside the reviewed one.
                'engine_estimate' => Arr::get($payload, 'engine.estimate', []),
                'engine_boq_lines' => count(Arr::get($payload, 'engine.boq', [])),
                'wire_sizes' => Arr::get($payload, 'engine.wire_sizes', []),
                'panel_schedules' => count(Arr::get($payload, 'engine.panel_schedules', [])),
                'equipment' => count(Arr::get($payload, 'engine.equipment', [])),
                'circuits' => count(Arr::get($payload, 'engine.circuits', [])),
                'approved_items' => Arr::get($payload, 'metadata.final_item_total'),
                'labor_hours' => Arr::get($boq, 'totals.labor_hours'),
                'generated_at' => Arr::get($payload, 'generated_at'),
            ],
        ];
    }

    /**
     * The engine's response in the same shape as final_response.json.
     *
     * Lets one mapping serve both stages: the provisional job is built from exactly
     * the fields the reviewed one uses, so signing off changes values, not structure.
     *
     * @return array<string, mixed>
     */
    private function engineViewOf(AiResult $result): array
    {
        // What the engine actually counted — anything it flagged for review is out
        // until a person approves it, exactly as on the review screen.
        $counted = $result->reviews()
            ->where('status', SymbolReview::STATUS_APPROVED)
            ->get();

        $boq = $this->boq->fromReviews($counted);
        $upload = $result->upload;

        return [
            'final_counts' => $counted
                ->groupBy('name')
                ->map(fn ($rows) => (int) $rows->sum('final_count'))
                ->all(),
            'boq' => $boq,
            'drawing' => [
                'name' => $upload?->name ?? $result->project->drawing_name,
                'pages' => $result->page_count,
            ],
            'generated_at' => $result->received_at?->toISOString(),
            'metadata' => [
                'final_item_total' => (int) $counted->sum('final_count'),
                'engine_version' => $result->model_version,
            ],
            'engine' => [
                'run_id' => $result->run_id,
                'project_name' => $result->project_name,
                'processing_time' => $result->processing_time,
                'pipeline_status' => $result->pipeline_status ?? [],
                'warnings' => $result->warnings ?? [],
                'symbol_counts' => $result->symbol_counts ?? [],
                'estimate' => $result->ai_estimate ?? [],
                'boq' => $result->boqLines()->get()->map(fn ($line) => [
                    'item' => $line->item,
                    'quantity' => (float) $line->quantity,
                    'unit_price' => (float) $line->unit_price,
                ])->all(),
                'wire_sizes' => $result->wireSizes()->get()->map(fn ($wire) => [
                    'page' => $wire->page,
                    'size' => $wire->size,
                    'context' => $wire->context,
                    'count' => $wire->count,
                ])->all(),
                'panel_schedules' => $result->panelSchedules()->get()->all(),
                'equipment' => $result->equipment()->get()->all(),
                'circuits' => $result->circuits()->get()->all(),
            ],
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function budget(array $payload): ?float
    {
        $engine = Arr::get($payload, 'engine.estimate.grand_total');

        return (float) ($engine ?: Arr::get($payload, 'boq.totals.material_cost')) ?: null;
    }

    /** @param  array<string, mixed>  $payload */
    private function describe(array $payload, bool $reviewed): string
    {
        $items = (int) Arr::get($payload, 'metadata.final_item_total', 0);
        $symbols = count(Arr::get($payload, 'final_counts', []));
        $pages = (int) Arr::get($payload, 'drawing.pages', 0);

        return $reviewed
            ? "Created from a reviewed AI takeoff: {$items} approved items across {$symbols} symbol types on {$pages} drawing pages."
            : "Created from an AI takeoff awaiting review: {$items} detected items across {$symbols} symbol types on {$pages} drawing pages.";
    }
}
