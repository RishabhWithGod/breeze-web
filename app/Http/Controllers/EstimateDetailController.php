<?php

namespace App\Http\Controllers;

use App\Http\Resources\EstimateItemResource;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\JobAssignment;
use App\Models\Project;
use App\Notifications\EstimateStatusChanged;
use App\Services\Clients\ClientDirectory;
use App\Services\Clients\ProjectDirectory;
use App\Services\Export\EstimatePdfWriter;
use App\Services\Takeoff\EstimateBuilder;
use App\Services\Takeoff\TakeoffFlow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The estimate screen: generated lines, editable rates, and the totals derived
 * from them.
 *
 * Totals are never stored independently of the lines — every write recomputes
 * them, so the header figures cannot drift from the table.
 */
class EstimateDetailController extends Controller
{
    public function __construct(private readonly ClientDirectory $clients) {}

    public function show(Request $request, Estimate $estimate): Response
    {
        $this->authorize('view', $estimate);

        // Opened as a step, so the flow is remembered from here too — coming
        // back to an estimate is a normal way to re-enter it.
        if ($request->boolean('flow') && $estimate->aiResult?->project !== null) {
            app(TakeoffFlow::class)->remember($estimate->aiResult->project);
        }

        $estimate->load([
            'job', 'takeoffProject', 'items', 'clientRecord',
            'aiResult.wireSizes', 'aiResult.equipment', 'aiResult.panelSchedules',
        ]);

        return Inertia::render('EstimateShow', [
            'estimate' => [
                'id' => $estimate->id,
                'number' => $estimate->number,
                'client' => $estimate->client,
                'status' => $estimate->status,
                'issuedOn' => $estimate->issued_on?->toDateString(),
                'notes' => $estimate->notes,
                'jobId' => $estimate->job_id,
                'jobName' => $estimate->job?->name,
                'clientId' => $estimate->client_id,
                'aiResultId' => $estimate->ai_result_id,
                'fromTakeoff' => $estimate->ai_result_id !== null,
                'convertedProjectId' => $estimate->converted_project_id,
                'createdAt' => $estimate->created_at->toISOString(),
                /*
                 * Where the numbers came from: the drawing, and the reviewed
                 * table. Guarded on the drawing itself, not on `project_id` —
                 * that now names the client, and a client without a takeoff has
                 * no drawing to open.
                 */
                'drawingUrl' => $estimate->takeoffProject?->drawing_name
                    ? route('drawings.show', $estimate->project_id)
                    : null,
                'drawingName' => $estimate->takeoffProject?->drawing_name,
                // Carries how the estimate was reached, so editing and saving
                // land back where the person actually came from.
                'editUrl' => route('estimates.edit', $this->showParams($request, $estimate)),
                // False while the lines still carry the engine's own quantities.
                'reviewed' => $estimate->aiResult === null
                    ? true
                    : $estimate->aiResult->isFinalised(),
                'reviewUrl' => $estimate->ai_result_id
                    ? route('reviews.show', $estimate->ai_result_id)
                    : null,
            ],
            /*
             * The roadmap belongs to the takeoff flow, and this screen is
             * reached from outside it too — from a job's estimates list, from
             * the estimates index. Only the flow's own links say so, so opening
             * an estimate to read it does not claim to be a step in anything.
             */
            'inFlow' => $request->boolean('flow'),
            /** Where Back goes — see `backUrl()`. */
            'backUrl' => $this->backUrl($request, $estimate),

            'items' => EstimateItemResource::collection($estimate->items)->resolve(),
            'sections' => EstimateBuilder::summarise($estimate),
            'totals' => EstimateBuilder::totalsFor($estimate),
            'categories' => collect(EstimateItem::CATEGORIES)
                ->map(fn (string $category) => [
                    'value' => $category,
                    'label' => EstimateItem::CATEGORY_LABELS[$category],
                ]),
            'statuses' => Estimate::STATUSES,
            /*
             * What a labor line defaults to — this client's own rate where
             * they have set one, since that is what every hour on their
             * estimates is actually billed at; the configured default
             * otherwise. The "Add a line" form fills this in the moment
             * Labor is picked, so an estimator never has to remember the
             * number, only correct it when a particular hour really did
             * cost something else.
             */
            'laborRate' => $estimate->clientRecord?->effectiveLaborRate()
                ?? (float) config('ai.estimating.labor_rate'),

            /*
             * Read off the same drawing but not priced by the engine: wire runs are
             * measured by length, and schedules describe equipment rather than count
             * it. They sit beside the lines as reference, and can be turned into
             * lines by hand.
             */
            'drawingData' => [
                'wireSizes' => $estimate->aiResult?->wireSizes->map(fn ($wire) => [
                    'page' => $wire->page,
                    'size' => $wire->size,
                    'context' => $wire->context,
                    'count' => $wire->count,
                ])->all() ?? [],
                'equipment' => $estimate->aiResult?->equipment->map(fn ($item) => [
                    'page' => $item->page,
                    'tag' => $item->tag,
                    'description' => $item->description,
                    'rating' => $item->rating,
                    'quantity' => $item->quantity,
                ])->all() ?? [],
                'panelSchedules' => $estimate->aiResult?->panelSchedules->map(fn ($panel) => [
                    'page' => $panel->page,
                    'panelName' => $panel->panel_name,
                    'rows' => $panel->rows ?? [],
                    'rawHeaders' => $panel->raw_headers ?? [],
                ])->all() ?? [],
            ],
        ]);
    }

    /**
     * How this estimate was reached, carried on every link that leads back to
     * it — Back, the edit form, and the redirect after saving.
     *
     * Two markers, and only one applies at a time: `from_job` for a job's own
     * list, `flow` for the takeoff roadmap. `from_job` is checked against the
     * estimate rather than trusted — an id that does not own this estimate is
     * somebody guessing at a URL, not navigation.
     *
     * @return array<string, int>
     */
    private function originParams(Request $request, Estimate $estimate): array
    {
        return array_filter([
            'from_job' => $request->integer('from_job') === $estimate->job_id
                ? $estimate->job_id
                : null,
            'flow' => $request->boolean('flow') ? 1 : null,
        ]);
    }

    /** @return array<string, int> */
    private function showParams(Request $request, Estimate $estimate): array
    {
        return ['estimate' => $estimate->id, ...$this->originParams($request, $estimate)];
    }

    /**
     * Where Back goes from an estimate.
     *
     * The screen is reached from three places and Back has to mean the one it
     * was actually reached from. A job's own list says so with `from_job`,
     * checked against the estimate rather than trusted — an id that does not
     * own this estimate is somebody guessing at a URL, not navigation.
     */
    private function backUrl(Request $request, Estimate $estimate): string
    {
        $fromJob = $request->integer('from_job') ?: null;

        if ($fromJob !== null && $fromJob === $estimate->job_id) {
            return route('jobs.show', $fromJob);
        }

        // In the flow, the step before is the review these numbers came from.
        if ($request->boolean('flow') && $estimate->takeoffProject?->drawing_name) {
            return route('reviews.show', $estimate->ai_result_id);
        }

        return route('estimates.index');
    }

    /**
     * Full-page edit form for the estimate's header.
     *
     * A screen rather than an inline panel, matching how a job is edited: the
     * detail screen stays a readable record, and changes are a deliberate step.
     */
    public function edit(Request $request, Estimate $estimate): Response
    {
        $this->authorize('update', $estimate);

        $estimate->load(['job', 'takeoffProject', 'aiResult']);

        return Inertia::render('EstimateEdit', [
            'estimate' => [
                'id' => $estimate->id,
                'number' => $estimate->number,
                'client' => $estimate->client,
                /*
                 * Who it is for and what it is on, both opened already chosen.
                 * The client falls back to the project's — an estimate raised
                 * before `client_id` was writable has the project and nothing
                 * else, and asking for a client the record can already name is
                 * work nobody should have to do twice.
                 */
                'clientId' => $estimate->client_id ?? $estimate->takeoffProject?->client_id,
                'projectId' => $estimate->project_id,
                'status' => $estimate->status,
                'issuedOn' => $estimate->issued_on?->toDateString(),
                'markupPct' => (float) $estimate->markup_pct,
                'taxPct' => (float) $estimate->tax_pct,
                'notes' => $estimate->notes,
                'jobId' => $estimate->job_id,
                'jobName' => $estimate->job?->name,
                'fromTakeoff' => $estimate->ai_result_id !== null,
                // Guarded on the drawing, not the client link — see show().
                'drawingUrl' => $estimate->takeoffProject?->drawing_name
                    ? route('drawings.show', $estimate->project_id)
                    : null,
            ],
            'statuses' => Estimate::STATUSES,
            /*
             * Back from editing is the estimate itself, carrying whatever
             * brought us here — so the chain out stays one screen at a time.
             */
            'backUrl' => route('estimates.show', $this->showParams($request, $estimate)),
            /*
             * The form posts here rather than to a bare `/estimates/{id}`, so
             * the origin survives the save — a PUT carries no query string of
             * its own to inherit it from.
             */
            'saveUrl' => route('estimates.update', $this->showParams($request, $estimate)),

            // Shown beside the rate fields so the effect of a change is visible.
            'totals' => EstimateBuilder::totalsFor($estimate),
            'clients' => $this->clients->options($request->user()),
            // Their projects — the list narrows to the picked client's.
            'projects' => app(ProjectDirectory::class)->options($request->user()),
        ]);
    }

    /** Header fields: client, status, dates, markup, tax, notes. */
    public function update(Request $request, Estimate $estimate): RedirectResponse
    {
        $this->authorize('update', $estimate);

        $userId = $request->user()->id;

        $validated = $request->validate([
            /*
             * What the estimate is on. Every drawing and takeoff hangs off a
             * project, so the estimate does too — and it is the one thing that
             * has to be answered, because the client is read from it. Must be
             * one of this manager's own.
             */
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->where('user_id', $userId)],
            /*
             * Who it is for. Sent by the form because that is the field the
             * project list is narrowed by, but not required: a project belongs
             * to exactly one client, and Estimate::booted derives the column
             * from the project either way.
             */
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where('user_id', $userId)],
            'status' => ['required', Rule::in(Estimate::STATUSES)],
            'issued_on' => ['required', 'date'],
            'markup_pct' => ['required', 'numeric', 'min:0', 'max:200'],
            'tax_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        /*
         * The two name snapshots the record prints from: who it is for, and
         * what it is on. Both are read from the project rather than trusted
         * from the form, so the record cannot name one client and point at
         * another one's project — and a project with no client record yet falls
         * back to the name it carries, because neither column may be empty.
         */
        $project = Project::where('user_id', $userId)->with('clientRecord:id,name')->find($validated['project_id']);
        $validated['client_id'] = $project?->client_id;
        $validated['client'] = $project?->clientRecord?->name ?? $project?->client ?? 'Unassigned';
        $validated['project'] = $project?->name ?? $validated['client'];

        $previousStatus = $estimate->status;
        $estimate->update($validated);
        $estimate->recalculateTotals();

        $estimate->aiResult?->recordHistory(
            'estimate_updated',
            "Estimate {$estimate->number} updated",
            to: '$'.number_format((float) $estimate->fresh()->grand_total, 2),
        );

        if ($previousStatus !== $estimate->status
            && in_array($estimate->status, [EstimateStatusChanged::APPROVED, EstimateStatusChanged::REJECTED], true)) {
            $this->notifyOfStatusChange($request, $estimate);
        }

        /*
         * Saving keeps whatever brought you here. Without it the trail ends at
         * the save: Back from the estimate would drop you in the estimates list
         * rather than the job whose list you opened it from.
         */
        return redirect()
            ->route('estimates.show', $this->showParams($request, $estimate))
            ->with('success', "Estimate {$estimate->number} updated.");
    }

    /**
     * Mirrors `NotifyManagerOfEstimate`'s recipient resolution — the job's
     * Project Manager, falling back to the takeoff owner when there isn't
     * one — excluding whoever just made the change themselves.
     */
    private function notifyOfStatusChange(Request $request, Estimate $estimate): void
    {
        $manager = $estimate->job
            ?->activeAssignments()
            ->where('role', JobAssignment::ROLE_PROJECT_MANAGER)
            ->with('user')
            ->first()
            ?->user;

        $recipient = $manager ?? $estimate->takeoffProject?->user;

        if ($recipient && $recipient->id !== $request->user()->id) {
            $recipient->notify(new EstimateStatusChanged($estimate, $estimate->status));
        }
    }

    public function storeItem(Request $request, Estimate $estimate): RedirectResponse
    {
        $this->authorize('update', $estimate);

        $validated = $request->validate([
            'category' => ['required', Rule::in(EstimateItem::CATEGORIES)],
            'description' => ['required', 'string', 'max:200'],
            'unit' => ['required', 'string', 'max:12'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'unit_cost' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $item = $estimate->items()->create([
            ...$validated,
            'source' => 'manual',
            'position' => (int) $estimate->items()->max('position') + 1,
        ]);

        $estimate->recalculateTotals();

        $estimate->aiResult?->recordHistory(
            'estimate_line_added',
            "Added “{$item->description}” to estimate {$estimate->number}",
            to: '$'.number_format((float) $item->total, 2),
        );

        return back()->with('success', "“{$item->description}” added.");
    }

    public function updateItem(Request $request, Estimate $estimate, EstimateItem $item): RedirectResponse
    {
        $this->authorize('update', $estimate);
        abort_unless($item->estimate_id === $estimate->id, 404);

        $validated = $request->validate([
            'category' => ['required', Rule::in(EstimateItem::CATEGORIES)],
            'description' => ['required', 'string', 'max:200'],
            'unit' => ['required', 'string', 'max:12'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'unit_cost' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $before = (float) $item->total;
        $item->update($validated);
        $estimate->recalculateTotals();

        $estimate->aiResult?->recordHistory(
            'estimate_line_updated',
            "Updated “{$item->description}” on estimate {$estimate->number}",
            from: '$'.number_format($before, 2),
            to: '$'.number_format((float) $item->fresh()->total, 2),
        );

        return back()->with('success', "“{$item->description}” updated.");
    }

    public function destroyItem(Estimate $estimate, EstimateItem $item): RedirectResponse
    {
        $this->authorize('update', $estimate);
        abort_unless($item->estimate_id === $estimate->id, 404);

        $description = $item->description;
        $item->delete();
        $estimate->recalculateTotals();

        $estimate->aiResult?->recordHistory(
            'estimate_line_removed',
            "Removed “{$description}” from estimate {$estimate->number}",
        );

        return back()->with('warning', "“{$description}” removed.");
    }

    /** Client-ready PDF. */
    public function pdf(Estimate $estimate, EstimatePdfWriter $writer): StreamedResponse
    {
        $this->authorize('view', $estimate);

        $contents = $writer->render($estimate);

        return ResponseFactory::streamDownload(
            fn () => print $contents,
            "estimate-{$estimate->number}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }

    /** CSV of the line items, for a spreadsheet workflow. */
    public function exportCsv(Estimate $estimate): StreamedResponse
    {
        $this->authorize('view', $estimate);

        $rows = $estimate->items()->get();

        return ResponseFactory::streamDownload(function () use ($estimate, $rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Category', 'Description', 'Unit', 'Quantity', 'Unit cost', 'Total']);

            foreach ($rows as $item) {
                fputcsv($handle, [
                    EstimateItem::CATEGORY_LABELS[$item->category] ?? $item->category,
                    $item->description,
                    $item->unit,
                    (float) $item->quantity,
                    (float) $item->unit_cost,
                    (float) $item->total,
                ]);
            }

            fputcsv($handle, []);
            foreach ([
                'Subtotal' => $estimate->subtotal,
                "Markup ({$estimate->markup_pct}%)" => $estimate->markup_total,
                "Tax ({$estimate->tax_pct}%)" => $estimate->tax_total,
                'Grand total' => $estimate->grand_total,
            ] as $label => $value) {
                fputcsv($handle, ['', $label, '', '', '', (float) $value]);
            }

            fclose($handle);
        }, "estimate-{$estimate->number}.csv", ['Content-Type' => 'text/csv']);
    }
}
