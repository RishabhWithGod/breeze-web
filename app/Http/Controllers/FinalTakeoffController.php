<?php

namespace App\Http\Controllers;

use App\Http\Resources\ApprovalHistoryResource;
use App\Http\Resources\FinalSymbolResource;
use App\Models\AiResult;
use App\Models\FinalSymbol;
use App\Models\Job;
use App\Models\Project;
use App\Models\Team;
use App\Services\Ai\ArtefactStore;
use App\Services\Clients\ClientDirectory;
use App\Services\Clients\JobSites;
use App\Services\Clients\ProjectDirectory;
use App\Services\Export\AnnotatedPdfWriter;
use App\Services\Export\SymbolExporter;
use App\Services\Takeoff\EstimateBuilder;
use App\Services\Takeoff\JobFactory;
use App\Services\Takeoff\TakeoffFlow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The signed-off takeoff: the final symbol table, its exports, and the two
 * actions that carry it forward into a job and an estimate.
 *
 * Everything on this screen is read from final_response.json / `final_symbols`,
 * never from the AI response.
 */
class FinalTakeoffController extends Controller
{
    public function show(
        Request $request,
        AiResult $result,
        ArtefactStore $store,
    ): Response {
        $this->authorize('view', $result);

        // Remembered so the flow can be left and picked up again.
        if ($result->project !== null) {
            app(TakeoffFlow::class)->remember($result->project);
        }

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'source' => ['nullable', Rule::in(['all', 'template', 'vector', 'vision', 'ocr'])],
            'sort' => ['nullable', Rule::in(FinalSymbol::SORTS)],
        ]);

        $source = $filters['source'] ?? 'all';
        $sort = $filters['sort'] ?? 'count-desc';

        $symbols = $result->finalSymbols()
            ->where('count', '>', 0)
            ->reorder()
            ->search($filters['search'] ?? null)
            ->source($source)
            ->sorted($sort)
            ->paginate(15)
            ->withQueryString();

        $result->load([
            'project.addresses', 'workJob.addresses', 'estimate', 'upload',
            'wireSizes', 'panelSchedules', 'equipment', 'circuits', 'boqLines',
        ]);
        $payload = $result->final_payload ?? [];

        return Inertia::render('FinalSymbols', [
            'result' => [
                'id' => $result->id,
                'projectId' => $result->project_id,
                'projectName' => $result->project->name,
                'drawingName' => $result->project->drawing_name,
                'modelVersion' => $result->model_version,
                'isFinalised' => $result->isFinalised(),
                'finalisedAt' => $result->finalised_at?->toISOString(),
                'pageCount' => $result->page_count,
                'workJobId' => $result->work_job_id,
                'workJobName' => $result->workJob?->name,
                /*
                 * The job as it stands, so the form on this screen is the same
                 * form after it is raised as before — coming back to this step
                 * shows what was filled in, not a card about it.
                 */
                'job' => $result->workJob === null ? null : [
                    'name' => $result->workJob->name,
                    'projectId' => $result->workJob->project_id,
                    'addressIds' => $result->workJob->addresses->pluck('id')->all(),
                    'description' => $result->workJob->description,
                    'jobType' => $result->workJob->job_type,
                    'teamId' => $result->workJob->team_id,
                    'startDate' => $result->workJob->start_date?->toDateString(),
                    'endDate' => $result->workJob->end_date?->toDateString(),
                    'budget' => $result->workJob->budget === null
                        ? null
                        : (float) $result->workJob->budget,
                ],
                'estimateId' => $result->estimate_id,
                'estimateNumber' => $result->estimate?->number,
                'hasAnnotatedPdf' => $store->exists($result->upload?->annotated_path),
                // Reported by the engine, carried onto this screen unchanged.
                'runId' => $result->run_id,
                'processingTime' => $result->processing_time,
                'pipelineStatus' => $result->pipelineStages(),
                'warnings' => $result->warnings ?? [],
                'engineEstimate' => $result->ai_estimate ?? [],
            ],
            'symbols' => FinalSymbolResource::collection($symbols),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'source' => $source,
                'sort' => $sort,
            ],
            'totals' => [
                'symbolTypes' => $result->finalSymbols()->where('count', '>', 0)->count(),
                'items' => (int) $result->finalSymbols()->where('count', '>', 0)->sum('count'),
                'approved' => data_get($payload, 'metadata.approved', 0),
                'rejected' => data_get($payload, 'metadata.rejected', 0),
                'modified' => data_get($payload, 'metadata.modified', 0),
                'aiItems' => data_get($payload, 'metadata.ai_item_total', 0),
                'laborHours' => data_get($payload, 'boq.totals.labor_hours', 0),
                'materialCost' => data_get($payload, 'boq.totals.material_cost', 0),
            ],
            'boq' => [
                'lines' => data_get($payload, 'boq.lines', []),
                'materials' => data_get($payload, 'boq.materials', []),
            ],

            // The engine's own priced bill of quantities, beside the reviewed one.
            'engineBoq' => $result->boqLines->map(fn ($line) => [
                'item' => $line->item,
                'description' => $line->description,
                'quantity' => (float) $line->quantity,
                'unit' => $line->unit,
                'unitPrice' => (float) $line->unit_price,
                'subtotal' => (float) $line->subtotal,
                'matchedSymbol' => $line->matched_symbol,
            ])->all(),

            // Everything else the engine read off the drawing.
            'wireSizes' => $result->wireSizes->map(fn ($wire) => [
                'page' => $wire->page,
                'size' => $wire->size,
                'context' => $wire->context,
                'count' => $wire->count,
            ])->all(),
            'panelSchedules' => $result->panelSchedules->map(fn ($panel) => [
                'page' => $panel->page,
                'panelName' => $panel->panel_name,
                'rows' => $panel->rows ?? [],
                'rawHeaders' => $panel->raw_headers ?? [],
            ])->all(),
            'equipment' => $result->equipment->map(fn ($item) => [
                'page' => $item->page,
                'tag' => $item->tag,
                'description' => $item->description,
                'rating' => $item->rating,
                'quantity' => $item->quantity,
            ])->all(),
            'circuits' => $result->circuits->map(fn ($circuit) => [
                'page' => $circuit->page,
                'number' => $circuit->number,
                'description' => $circuit->description,
                'breaker' => $circuit->breaker,
                'panel' => $circuit->panel,
            ])->all(),
            /*
             * The whole register, and which of them this takeoff is for. The
             * takeoff's own client is the default rather than a rule — work is
             * sometimes taken off one client's drawing and built for another —
             * and `ai_result_id` still records where the numbers came from.
             */
            /*
             * Every project, so the job can be raised against another one —
             * work is sometimes taken off one project's drawing and built under
             * a different one. The takeoff's own is where the form starts.
             */
            'projects' => app(ProjectDirectory::class)->options($request->user()),
            // The crews this job can be handed to — the same list Create Job
            // offers, because this is the same step reached from the takeoff.
            'teams' => Team::orderBy('name')->get(['id', 'name']),
            'defaultProjectId' => $result->project_id,
            'history' => ApprovalHistoryResource::collection(
                $result->history()->with('actor')->take(20)->get()
            )->resolve(),
        ]);
    }

    /** JSON / CSV / XLSX of the reviewed table. */
    public function export(
        AiResult $result,
        string $format,
        SymbolExporter $exporter,
    ): StreamedResponse|BinaryFileResponse {
        $this->authorize('view', $result);
        abort_unless(in_array($format, ['json', 'csv', 'xlsx'], true), 404);

        $symbols = $result->finalSymbols()->where('count', '>', 0)->get();
        $slug = str($result->project->name)->slug()->value();

        if ($format === 'json') {
            $payload = $exporter->json($result);

            return ResponseFactory::streamDownload(
                fn () => print json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                "final_response-{$slug}.json",
                ['Content-Type' => 'application/json'],
            );
        }

        if ($format === 'csv') {
            $contents = $exporter->csv($symbols);

            return ResponseFactory::streamDownload(
                fn () => print $contents,
                "final-symbols-{$slug}.csv",
                ['Content-Type' => 'text/csv'],
            );
        }

        return ResponseFactory::download(
            $exporter->xlsx($symbols, $result->project->name),
            "final-symbols-{$slug}.xlsx",
        )->deleteFileAfterSend();
    }

    /**
     * The annotated drawing. Rendered on first request and cached on the artefact
     * disk; `?refresh=1` re-renders it after further review.
     */
    public function annotated(
        Request $request,
        AiResult $result,
        AnnotatedPdfWriter $writer,
        ArtefactStore $store,
    ): StreamedResponse {
        $this->authorize('view', $result);

        $path = $result->upload?->annotated_path;

        if ($request->boolean('refresh') || ! $store->exists($path)) {
            $path = $writer->write($result);
        }

        return $store->disk()->download(
            $path,
            'annotated-'.str($result->project->name)->slug().'.pdf',
        );
    }

    /**
     * Creates the job from final_response.json, and prices it straight away.
     *
     * One action rather than two: the reviewed document already contains the
     * quantities *and* the engine's bill of quantities, so a job without its
     * estimate would just be a step waiting to be repeated. A failure to price
     * still leaves the job — the estimate can be raised again from either screen.
     */
    public function storeJob(
        Request $request,
        AiResult $result,
        JobFactory $factory,
        EstimateBuilder $estimateBuilder,
        JobSites $sites,
    ): RedirectResponse {
        $this->authorize('view', $result);

        /*
         * A job is built from final_response.json, so the review has to be signed off
         * first. Answering with guidance rather than a bare 403 keeps the workflow
         * navigable: the reviewer is sent back to finish, not into an error screen.
         */
        if (! $result->isFinalised()) {
            return $this->requireFinalisedReview($result, 'a job');
        }

        $attributes = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            /*
             * Defaults to the takeoff's own client, but can name another. The
             * sites must then be that client's, and the job's own address is
             * written from the first one picked rather than typed.
             */
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            // One site per job — the same rule the standalone job form applies.
            'address_ids' => ['nullable', 'array', 'max:1'],
            'address_ids.*' => ['integer', 'distinct', 'exists:client_addresses,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'job_type' => ['nullable', Rule::in(Job::TYPES)],
            // The crew, as the Create Job screen asks for it — this is the same
            // step of the same flow, reached from the takeoff instead.
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            // The same two the Create Job screen requires — this is the same
            // step of the same flow, reached from the takeoff instead.
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'budget' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
        ], [
            'start_date.required' => 'Pick the day this job starts',
            'end_date.required' => 'Pick the day this job is due to finish',
            'end_date.after_or_equal' => 'End date must be on or after the start date',
        ]);

        $addressIds = $attributes['address_ids'] ?? [];
        unset($attributes['address_ids']);

        $projectId = (int) ($attributes['project_id'] ?? $result->project_id);

        // The client is whoever that project is for — not asked for twice.
        $clientId = (int) Project::whereKey($projectId)->value('client_id');
        $attributes['client_id'] = $clientId ?: null;

        // Refused before the job is written: the sites must belong to that
        // client's book, whatever ids arrived.
        $addresses = $addressIds === []
            ? collect()
            : $sites->resolve($clientId, $addressIds);

        // A job raised from this takeoff already: the form on this screen is
        // then that job's own, so re-submitting it saves the edits rather than
        // quietly doing nothing. `fromFinalJson` only refreshes the counts.
        $existed = $result->work_job_id !== null;

        $typed = array_filter($attributes, fn ($value) => filled($value));

        try {
            $job = $factory->fromFinalJson($result, $request->user(), $typed);
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        if ($existed && $typed !== []) {
            // The client's name is a snapshot on the job, so changing the client
            // has to rewrite it — every list reads that column, not the join.
            $job->update(app(ClientDirectory::class)->withClientSnapshot($typed));
        }

        if ($addresses->isNotEmpty()) {
            $sites->attach($job, $addresses);
        }

        $estimate = null;

        try {
            $estimate = $estimateBuilder->fromFinalJson($result->refresh(), $request->user(), $job);
        } catch (RuntimeException $e) {
            /*
             * Straight on to breaking the job into tasks, the same as Create Job.
             * Without the estimate there are no lines to plan from, but the step
             * still takes typed tasks and can be skipped.
             */
            return redirect()
                ->route('jobs.tasks.setup', $job)
                ->with('success', "“{$job->name}” was created from the reviewed takeoff.")
                ->with('warning', "The estimate could not be generated: {$e->getMessage()}");
        }

        // The estimate exists now, so the task step has its lines to plan from.
        return redirect()
            ->route('jobs.tasks.setup', $job)
            ->with(
                'success',
                "“{$job->name}” was created from the reviewed takeoff, priced as {$estimate->number}."
            );
    }

    /**
     * Sends the reviewer back to finish the review, explaining why.
     *
     * `AiResultPolicy::convert` is still the security boundary; this is the humane
     * path for the ordinary case of clicking too early.
     */
    private function requireFinalisedReview(AiResult $result, string $what): RedirectResponse
    {
        return redirect()
            ->route('reviews.show', $result)
            ->with(
                'warning',
                "Please finish reviewing the drawing before creating {$what} — it's built "
                .'from what you approve there.'
            );
    }

    /** Generates the estimate from final_response.json. */
    public function storeEstimate(Request $request, AiResult $result, EstimateBuilder $builder): RedirectResponse
    {
        $this->authorize('view', $result);

        if (! $result->isFinalised()) {
            return $this->requireFinalisedReview($result, 'an estimate');
        }

        try {
            $estimate = $builder->fromFinalJson($result, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return redirect()
            ->route('estimates.show', ['estimate' => $estimate, 'flow' => 1])
            ->with('success', "Estimate {$estimate->number} was generated from the reviewed takeoff.");
    }
}
