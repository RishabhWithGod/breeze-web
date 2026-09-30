<?php

namespace App\Http\Controllers;

use App\Models\Estimate;
use App\Models\EstimateBuilderLine;
use App\Models\Project;
use App\Services\Estimating\EstimateApprovals;
use App\Services\Estimating\EstimateWorksheet;
use App\Support\Ownership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Estimate Builder: turns quantities into priced labor and material lines.
 *
 * Built for the people who price work — a Project Manager, an Estimator or a
 * Supervisor — on the estimates of their own company. An estimate is editable here
 * while it is a draft; asking for approval sends it on to the Estimate Review and
 * Approval screen and locks the worksheet. A reviewer who sends it back reopens it.
 */
class EstimateBuilderController extends Controller
{
    /** Roles that price work. */
    private const ROLES = ['project manager', 'estimator', 'supervisor', 'admin', 'owner'];

    public function __construct(
        private readonly EstimateWorksheet $worksheet,
        private readonly EstimateApprovals $approvals,
    ) {}

    /** The builder's front door: estimates being built, and the way to start another. */
    public function index(Request $request): Response
    {
        $this->authorizeRole($request);

        return Inertia::render('EstimateBuilderHome', [
            'estimates' => Estimate::query()
                ->ownedBy($request->user())
                ->where('builder_managed', true)
                ->withCount('builderLines')
                ->latest('id')
                ->get()
                ->map(fn (Estimate $estimate) => [
                    'id' => $estimate->id,
                    'number' => $estimate->number,
                    'project' => $estimate->project,
                    'client' => $estimate->client,
                    'status' => $estimate->status,
                    'lines' => $estimate->builder_lines_count,
                    'total' => (float) $estimate->grand_total,
                    'updatedAt' => $estimate->updated_at?->toISOString(),
                ])
                ->values(),
            'projects' => Project::query()
                ->ownedBy($request->user())
                ->orderBy('name')
                ->get(['id', 'name', 'client'])
                ->map(fn (Project $project) => ['id' => $project->id, 'name' => $project->name, 'client' => $project->client]),
        ]);
    }

    /** Starts a new estimate on a project, ready to be built. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeRole($request);

        $data = $request->validate([
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->whereIn('user_id', Ownership::userIdList($request->user()))],
        ], ['project_id.required' => 'Choose the project this estimate is for.']);

        $project = Project::query()->with('clientRecord:id,name')->findOrFail($data['project_id']);
        $client = $project->clientRecord?->name ?? $project->client ?? 'Unassigned';

        $estimate = Estimate::create([
            'user_id' => $request->user()->id,
            'project_id' => $project->id,
            'client_id' => $project->client_id,
            'client' => $client,
            'project' => $project->name,
            'number' => Estimate::nextNumber($request->user()),
            'issued_on' => now()->toDateString(),
            'amount' => 0,
            'status' => 'draft',
            'kind' => Estimate::KIND_STANDALONE,
            'builder_managed' => true,
            'markup_pct' => 15,
            'tax_pct' => 0,
            // Its own copy of the usual exclusions, for the estimator to edit.
            'exclusions' => config('estimates.default_exclusions'),
        ]);

        return redirect()->route('estimate-builder.show', $estimate)->with('success', "{$estimate->number} is ready to build.");
    }

    public function show(Request $request, Estimate $estimate): Response
    {
        $this->authorizeEstimate($request, $estimate);

        $estimate->load('takeoffSource:id,number,project', 'reviewer:id,name');

        return Inertia::render('EstimateBuilder', [
            'estimate' => [
                'id' => $estimate->id,
                'number' => $estimate->number,
                'project' => $estimate->project,
                'client' => $estimate->client,
                'status' => $estimate->status,
                // Only a draft can still be changed here.
                'locked' => $estimate->status !== 'draft',
                'taxPct' => (float) $estimate->tax_pct,
                'markupPct' => (float) $estimate->markup_pct,
                'laborRate' => (float) $estimate->builder_labor_rate,
                'commodityVersion' => $estimate->commodity_version,
                'revisions' => $estimate->revisions()->count(),
                'scopeOfWork' => $estimate->scope_of_work,
                'exclusions' => $estimate->exclusions ?? [],
                // A reviewer sent it back: what they wrote, who and when.
                'returned' => $estimate->status === 'draft' && $estimate->reviewed_at !== null
                    ? ['notes' => $estimate->review_notes, 'by' => $estimate->reviewer?->name, 'at' => $estimate->reviewed_at->toISOString()]
                    : null,
                'takeoffSource' => $estimate->takeoffSource
                    ? ['id' => $estimate->takeoffSource->id, 'number' => $estimate->takeoffSource->number, 'project' => $estimate->takeoffSource->project]
                    : null,
            ],
            'lines' => $estimate->builderLines->map(fn (EstimateBuilderLine $line) => [
                'id' => $line->id,
                'description' => $line->description,
                'commodity' => $line->commodity,
                'unit' => $line->unit,
                'materialQty' => (float) $line->material_qty,
                'materialUnitPrice' => (float) $line->material_unit_price,
                'laborHours' => (float) $line->labor_hours,
                'laborRate' => (float) $line->labor_rate,
                'markupPct' => (float) $line->markup_pct,
                'source' => $line->source,
                'sourceEstimateItemId' => $line->source_estimate_item_id,
                'priceBookItemId' => $line->price_book_item_id,
            ])->values(),
            'priceList' => $this->worksheet->priceList($request->user()),
            'priceListVersion' => $this->worksheet->commodityVersion($request->user()),
            // The estimates and addenda of this project whose takeoff lines can be copied in.
            'importSources' => $this->importable($request, $estimate)
                ->withCount(['items as line_count' => fn ($items) => $items->whereNull('builder_line_id')])
                ->orderByRaw('kind = ?', [Estimate::KIND_ADDENDUM])
                ->orderBy('addendum_number')
                ->orderBy('id')
                ->limit(50)
                ->get(['id', 'number', 'project', 'client', 'kind', 'addendum_number', 'addendum_name'])
                ->map(fn (Estimate $source) => [
                    'id' => $source->id,
                    'number' => $source->number,
                    'project' => $source->project,
                    'client' => $source->client,
                    'kind' => $source->kind === Estimate::KIND_ADDENDUM ? 'addendum' : 'estimate',
                    'addendumNumber' => $source->addendum_number,
                    'addendumName' => $source->addendum_name,
                    'lines' => (int) $source->line_count,
                ])->values(),
        ]);
    }

    /** Saves the worksheet as a draft. */
    public function save(Request $request, Estimate $estimate): RedirectResponse
    {
        $this->authorizeEstimate($request, $estimate, editing: true);

        [$lines, $settings] = $this->validated($request, $estimate);
        $this->worksheet->save($estimate, $lines, $settings, $request->user());

        return back()->with('success', 'Draft saved.');
    }

    /** Saves, then sends the estimate on for review and approval. */
    public function requestApproval(Request $request, Estimate $estimate): RedirectResponse
    {
        $this->authorizeEstimate($request, $estimate, editing: true);

        [$lines, $settings] = $this->validated($request, $estimate);
        $note = $request->validate(['revision_note' => ['nullable', 'string', 'max:200']])['revision_note'] ?? null;

        if ($lines === []) {
            return back()->withErrors(['lines' => 'Add at least one item before asking for approval.']);
        }

        // A reviewer verifies the scope, so there has to be one to read.
        if (blank($settings['scope_of_work'] ?? null)) {
            return back()->withErrors(['scope_of_work' => 'Describe the scope of work in Estimate Settings before asking for approval.']);
        }

        $this->worksheet->save($estimate, $lines, $settings, $request->user());

        if ((float) $estimate->fresh()->grand_total <= 0) {
            return back()->withErrors(['lines' => 'Price at least one item before asking for approval.']);
        }

        $this->approvals->submit($estimate, $request->user(), $note);

        return redirect()->route('estimates.review', $estimate)
            ->with('success', "{$estimate->number} was sent for review and approval.");
    }

    /** Copies another estimate's takeoff lines onto the worksheet. */
    public function import(Request $request, Estimate $estimate): RedirectResponse
    {
        $this->authorizeEstimate($request, $estimate, editing: true);

        $data = $request->validate(['source_estimate_id' => ['required', 'integer']]);

        // Only this project's own estimates and addenda — never another project's, whatever id is sent.
        $source = $this->importable($request, $estimate)->find($data['source_estimate_id']);

        if ($source === null) {
            throw ValidationException::withMessages([
                'source_estimate_id' => 'Choose an estimate or addendum of this project that has lines to copy.',
            ]);
        }

        $added = $this->worksheet->importTakeoff($estimate, $source);

        return back()->with(
            $added > 0 ? 'success' : 'warning',
            $added > 0
                ? "{$added} ".str('line')->plural($added)." imported from {$source->number}."
                : "Everything in {$source->number} is already on the worksheet.",
        );
    }

    /**
     * What can be copied onto this worksheet: the other estimates and addenda of the
     * same project, in the same company, that have takeoff lines of their own.
     *
     * @return Builder<Estimate>
     */
    private function importable(Request $request, Estimate $estimate): Builder
    {
        return Estimate::query()
            ->ownedBy($request->user())
            ->where('project_id', $estimate->project_id)
            ->whereIn('kind', [Estimate::KIND_STANDALONE, Estimate::KIND_ADDENDUM])
            ->whereKeyNot($estimate->id)
            ->whereHas('items', fn ($items) => $items->whereNull('builder_line_id'));
    }

    /** The price list, searched — for picking an item and for the Commodity Pricing tab. */
    public function priceList(Request $request): JsonResponse
    {
        $this->authorizeRole($request);

        return response()->json($this->worksheet->priceList(
            $request->user(),
            $request->string('search')->trim()->value() ?: null,
            120,
        ));
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function validated(Request $request, Estimate $estimate): array
    {
        $data = $request->validate([
            'lines' => ['present', 'array', 'max:500'],
            'lines.*.id' => ['nullable', 'integer'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.commodity' => ['nullable', 'string', 'max:120'],
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
            'lines.*.material_qty' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'lines.*.material_unit_price' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'lines.*.labor_hours' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'lines.*.labor_rate' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'lines.*.markup_pct' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'lines.*.source' => ['nullable', Rule::in([EstimateBuilderLine::SOURCE_MANUAL, EstimateBuilderLine::SOURCE_TAKEOFF, EstimateBuilderLine::SOURCE_PRICE_LIST])],
            'lines.*.source_estimate_item_id' => ['nullable', 'integer', 'exists:estimate_items,id'],
            'lines.*.price_book_item_id' => ['nullable', 'integer', 'exists:price_book_items,id'],
            'settings.tax_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'settings.markup_pct' => ['required', 'numeric', 'min:0', 'max:1000'],
            'settings.labor_rate' => ['required', 'numeric', 'min:0', 'max:100000'],
            'settings.scope_of_work' => ['nullable', 'string', 'max:4000'],
            'settings.exclusions' => ['nullable', 'array', 'max:30'],
            'settings.exclusions.*' => ['nullable', 'string', 'max:200'],
        ], [
            'lines.*.description.required' => 'Every item needs a description.',
        ]);

        return [$data['lines'], $data['settings']];
    }

    private function authorizeRole(Request $request): void
    {
        abort_unless(
            in_array(mb_strtolower(trim((string) $request->user()->role)), self::ROLES, true),
            403,
        );
    }

    private function authorizeEstimate(Request $request, Estimate $estimate, bool $editing = false): void
    {
        $this->authorizeRole($request);
        abort_unless($estimate->builder_managed && Ownership::owns($request->user(), $estimate->user_id), 403);
        abort_if($editing && $estimate->status !== 'draft', 409, 'This estimate has been sent and can no longer be changed here.');
    }
}
