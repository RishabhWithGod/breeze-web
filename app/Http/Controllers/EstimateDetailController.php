<?php

namespace App\Http\Controllers;

use App\Http\Resources\EstimateItemResource;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\JobAssignment;
use App\Notifications\EstimateStatusChanged;
use App\Services\Clients\ClientDirectory;
use App\Services\Export\EstimatePdfWriter;
use App\Services\Takeoff\EstimateBuilder;
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

    public function show(Estimate $estimate): Response
    {
        $estimate->load([
            'job', 'takeoffProject', 'items',
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
                'projectId' => $estimate->project_id,
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
                'editUrl' => route('estimates.edit', $estimate),
                // False while the lines still carry the engine's own quantities.
                'reviewed' => $estimate->aiResult === null
                    ? true
                    : $estimate->aiResult->isFinalised(),
                'reviewUrl' => $estimate->ai_result_id
                    ? route('reviews.show', $estimate->ai_result_id)
                    : null,
            ],
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
     * Full-page edit form for the estimate's header.
     *
     * A screen rather than an inline panel, matching how a job is edited: the
     * detail screen stays a readable record, and changes are a deliberate step.
     */
    public function edit(Estimate $estimate): Response
    {
        $estimate->load(['job', 'takeoffProject', 'aiResult']);

        return Inertia::render('EstimateEdit', [
            'estimate' => [
                'id' => $estimate->id,
                'number' => $estimate->number,
                'client' => $estimate->client,
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
            // Shown beside the rate fields so the effect of a change is visible.
            'totals' => EstimateBuilder::totalsFor($estimate),
            'clients' => $this->clients->options(),
        ]);
    }

    /** Header fields: client, status, dates, markup, tax, notes. */
    public function update(Request $request, Estimate $estimate): RedirectResponse
    {
        $validated = $request->validate([
            /** The client, picked from the client register — see ClientDirectory. */
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'status' => ['required', Rule::in(Estimate::STATUSES)],
            'issued_on' => ['required', 'date'],
            'markup_pct' => ['required', 'numeric', 'min:0', 'max:200'],
            'tax_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // `client` and `project` are both snapshots of the picked client's name.
        $validated = $this->clients->withClientSnapshot($validated);
        $validated['project'] = $validated['client'];

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

        return redirect()
            ->route('estimates.show', $estimate)
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
