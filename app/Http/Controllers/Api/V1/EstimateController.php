<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\TimeEntry;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Project;
use App\Services\JobScope\JobScopeHistory;
use App\Services\Clients\ProjectDirectory;
use App\Services\Takeoff\EstimateBuilder;
use App\Support\Ownership;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Estimates, as the mobile app sees them: the same `ownedBy()` scope
 * `EstimateController::index` (web) uses — only estimates the signed-in
 * manager themselves created, addenda excluded exactly as the web list
 * excludes them (an addendum is never its own row; it belongs to, and is
 * only ever shown from, the original estimate it adds scope to).
 *
 * Deliberately minimal, matching the mobile Jobs endpoint's own precedent
 * (`JobController::index`): no server-side search/status/date filters —
 * the mobile list is small enough that the app filters client-side — and
 * no material/labor/equipment/markup cost breakdown, only the one total a
 * list row needs. Those stay exclusively in the manager-facing web detail
 * screen, same reasoning `JobController` gives for never sending `budget`.
 */
class EstimateController extends Controller
{
    use ApiResponses;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', ...Estimate::STATUSES])],
            'client' => ['nullable', 'string', 'max:120'],
            'project' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'sort' => ['nullable', Rule::in(Estimate::SORTS)],
        ]);
        $status = $filters['status'] ?? 'all';
        $client = $filters['client'] ?? 'all';
        $project = $filters['project'] ?? 'all';

        $base = fn () => Estimate::query()
            ->ownedBy($request->user())
            ->where('kind', '!=', Estimate::KIND_ADDENDUM);

        $estimates = $base()
            ->withCount('addenda')
            ->search($filters['search'] ?? null)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($client !== 'all', fn ($q) => $q->where('client', $client))
            ->when($project !== 'all', fn ($q) => $q->where('project', $project))
            ->issuedBetween($filters['date_from'] ?? null, $filters['date_to'] ?? null)
            ->sorted($filters['sort'] ?? 'date-desc')
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return $this->ok([
            'estimates' => $estimates->getCollection()->map(fn (Estimate $estimate) => [
                'id' => $estimate->id,
                'number' => $estimate->number,
                'client' => $estimate->client,
                'project' => $estimate->project,
                'issuedOn' => $estimate->issued_on->toDateString(),
                'amount' => (float) $estimate->amount,
                'status' => $estimate->status,
                'addendaCount' => (int) $estimate->addenda_count,
            ])->all(),
            'clients' => $base()->distinct()->orderBy('client')->pluck('client'),
            'projects' => $base()->whereNotNull('project')->distinct()->orderBy('project')->pluck('project'),
            'meta' => [
                'currentPage' => $estimates->currentPage(),
                'lastPage' => $estimates->lastPage(),
                'perPage' => $estimates->perPage(),
                'total' => $estimates->total(),
            ],
        ]);
    }

    /** What the Create Estimate form needs: the reference, who it can be for, their projects and drawings. */
    public function createOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        $defaultProjectId = $user->projects()->whereKey($request->integer('project'))->value('id');

        return $this->ok([
            'nextNumber' => Estimate::nextNumber($user),
            'clients' => app(\App\Services\Clients\ClientDirectory::class)->options($user)->map(fn (array $c) => [
                'id' => $c['id'],
                'name' => $c['name'],
            ])->values()->all(),
            'projects' => app(ProjectDirectory::class)->options($user)->map(fn (array $p) => [
                'id' => $p['id'],
                'clientId' => $p['clientId'],
                'name' => $p['name'],
                'defaultUploadId' => $p['defaultUploadId'] ?? null,
            ])->values()->all(),
            'uploads' => app(\App\Services\Takeoff\TakeoffLinkOptions::class)->uploads()->map(fn (array $u) => [
                'id' => $u['id'],
                'name' => $u['name'],
                'projectId' => $u['projectId'],
                'estimate' => $u['estimate'] ?? null,
            ])->values()->all(),
            'defaultProjectId' => $defaultProjectId,
        ]);
    }

    /** Raise an estimate — web's `EstimateController::store()`. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $userIds = Ownership::userIdList($user);

        $data = $request->validate([
            'issued_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'status' => ['required', Rule::in(Estimate::STATUSES)],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->whereIn('user_id', $userIds)],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->whereIn('user_id', $userIds)],
            'upload_id' => [
                'nullable', 'integer',
                Rule::exists('uploads', 'id')->where(
                    fn ($query) => $query->whereIn('project_id', fn ($sub) => $sub->select('id')->from('projects')->whereIn('user_id', $userIds))
                ),
            ],
        ], [
            'client_id.exists' => 'Pick a client from the list',
            'issued_on.required' => 'Date is required',
            'amount.required' => 'Amount is required',
        ]);

        $upload = isset($data['upload_id']) ? \App\Models\Upload::find($data['upload_id']) : null;
        $aiResult = $upload?->latestAiResult;

        // The drawing already has an estimate — nothing to create, only to open.
        if ($aiResult?->estimate) {
            return $this->fail("{$upload->label()} already has estimate {$aiResult->estimate->number}.", 422, [
                'upload_id' => ["{$upload->label()} already has estimate {$aiResult->estimate->number}."],
            ]);
        }
        unset($data['upload_id']);

        // Both name snapshots are read from the project, never trusted from the form.
        $project = Project::whereIn('user_id', Ownership::userIds($user))->with('clientRecord:id,name')->find($data['project_id'] ?? null);
        $data['client_id'] = $project?->client_id;
        $data['client'] = $project?->clientRecord?->name ?? $project?->client ?? 'Unassigned';
        $data['project'] = $project?->name ?? $data['client'];

        $estimate = Estimate::create([
            ...$data,
            'ai_result_id' => $aiResult?->id,
            'number' => Estimate::nextNumber($user),
        ]);
        $aiResult?->update(['estimate_id' => $estimate->id]);

        return $this->created([
            'id' => $estimate->id,
            'number' => $estimate->number,
            'client' => $estimate->client,
            'project' => $estimate->project,
        ], "{$estimate->number} was created.");
    }

    public function destroy(Request $request, Estimate $estimate): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $estimate->user_id), 403);

        $estimate->delete();

        return $this->ok(null, "{$estimate->number} was deleted.");
    }

    /** Undo for the delete above. */
    public function restore(Request $request, int $estimate): JsonResponse
    {
        $trashed = Estimate::onlyTrashed()->ownedBy($request->user())->findOrFail($estimate);
        $trashed->restore();

        return $this->ok(['id' => $trashed->id], "{$trashed->number} was restored.");
    }

    /**
     * The detail screen's shape — everything `index` deliberately leaves
     * out (line items, the cost breakdown, the linked takeoff) — since this
     * is always the estimate's own owner viewing it, not the field crew
     * `index`'s minimal row is designed for.
     */
    public function show(Request $request, Estimate $estimate): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $estimate->user_id), 403);

        $estimate->load([
            'items' => fn ($query) => $query->orderBy('category')->orderBy('position'),
            'takeoffProject', 'aiResult.wireSizes', 'aiResult.equipment', 'aiResult.panelSchedules',
            'job:id,name', 'parentEstimate:id,number',
        ]);

        // This estimate's own addenda, when it is the standalone one they
        // belong to — mobile's counterpart to web's own
        // `EstimateDetailController::show()`. Empty for an addendum or
        // merged estimate: only a standalone estimate is something else can
        // be an addendum for.
        $addenda = $estimate->kind === Estimate::KIND_STANDALONE
            ? $estimate->addenda()->with('items')->get()
            : collect();

        $matchedCount = $estimate->items
            ->whereIn('pricing_source', ['vendor-rate-list', 'price-book'])
            ->count();
        $engine = $estimate->aiResult?->ai_estimate ?? [];

        return $this->ok([
            'id' => $estimate->id,
            'number' => $estimate->number,
            'client' => $estimate->client,
            'project' => $estimate->project,
            'projectId' => $estimate->project_id,
            'aiResultId' => $estimate->ai_result_id,
            'fromTakeoff' => $estimate->ai_result_id !== null,
            // The takeoff (project) the estimate came out of — where "Review the symbols" and the flow's Job step live.
            'takeoffId' => $estimate->ai_result_id !== null ? $estimate->project_id : null,
            // The merge-selection workspace applies only before a job exists.
            'beforeJob' => $estimate->kind === Estimate::KIND_STANDALONE && $estimate->job_id === null,
            // What a labor line defaults to, and what the labor-rate field shows.
            'laborRate' => $this->currentLaborRate($estimate),
            'jobId' => $estimate->job_id,
            'jobName' => $estimate->job?->name,
            // Every change this estimate has had — who, from where, what — newest first.
            'changes' => app(JobScopeHistory::class)->forEstimate($estimate, (bool) $request->user()->can('viewJobCosts', TimeEntry::class), 20),
            'issuedOn' => $estimate->issued_on->toDateString(),
            'createdAt' => $estimate->created_at?->toISOString(),
            'amount' => (float) $estimate->amount,
            'status' => $estimate->status,
            'notes' => $estimate->notes,
            // `standalone` (the ordinary case), `addendum`, or `merged` —
            // see `Estimate::KINDS`.
            'kind' => $estimate->kind,
            'addendumNumber' => $estimate->addendum_number,
            'addendumName' => $estimate->addendum_name,
            // Set only when `kind` is `addendum` — the estimate this one
            // adds scope to.
            'parentEstimate' => $estimate->parentEstimate === null ? null : [
                'id' => $estimate->parentEstimate->id,
                'number' => $estimate->parentEstimate->number,
            ],
            // This estimate's own addenda — only populated when `kind` is
            // `standalone`. No line items here (unlike web's own pre-job
            // merge workspace): mobile opens an addendum on its own detail
            // screen for that, matching `Api\V1\AddendumController::index`'s
            // own summary-only shape.
            'addenda' => $addenda->map(fn (Estimate $addendum) => [
                'id' => $addendum->id,
                'number' => $addendum->number,
                'addendumNumber' => $addendum->addendum_number,
                'addendumName' => $addendum->addendum_name,
                'amount' => (float) $addendum->amount,
                'status' => $addendum->status,
                'createdAt' => $addendum->created_at?->toISOString(),
                // Each addendum's own saved lines — the pre-job merge workspace and
                // the per-addendum breakdown cards read these.
                'lineItems' => $addendum->items->sortBy(['category', 'position'])->map(fn (EstimateItem $item) => [
                    'id' => $item->id,
                    'category' => $item->category,
                    'description' => $item->description,
                    'unit' => $item->unit,
                    'quantity' => (float) $item->quantity,
                    'unitCost' => (float) $item->unit_cost,
                    'total' => (float) $item->total,
                    'source' => $item->source,
                    'pricingSource' => $item->pricing_source,
                    'pricingConfidence' => $item->pricing_confidence,
                ])->values()->all(),
            ])->values(),
            'materialTotal' => (float) $estimate->material_total,
            'laborTotal' => (float) $estimate->labor_total,
            'equipmentTotal' => (float) $estimate->equipment_total,
            'subtotal' => (float) $estimate->subtotal,
            'markupPct' => (float) $estimate->markup_pct,
            'markupTotal' => (float) $estimate->markup_total,
            'taxPct' => (float) $estimate->tax_pct,
            'taxTotal' => (float) $estimate->tax_total,
            'grandTotal' => (float) $estimate->grand_total,
            'laborHours' => round(
                (float) $estimate->items->where('category', EstimateItem::CATEGORY_LABOR)->sum('quantity'),
                2,
            ),
            // What the AI engine priced before review, for comparison — same
            // fields `EstimateBuilder::totalsFor()` sends web.
            'engineSubtotal' => (float) ($engine['subtotal'] ?? 0),
            'engineGrandTotal' => (float) ($engine['grand_total'] ?? 0),
            'engineLineCount' => (int) ($engine['line_count'] ?? 0),
            // Matched = priced off a known rate (this project's own vendor
            // rate list, or the price book); unmatched = priced at zero,
            // waiting on a rate.
            'matchedLines' => $matchedCount,
            'unmatchedLines' => $estimate->items->count() - $matchedCount,
            'drawingName' => $estimate->takeoffProject?->drawing_name,
            // False while the lines still carry the AI engine's own
            // pre-review quantities.
            'reviewed' => $estimate->aiResult === null ? true : $estimate->aiResult->isFinalised(),
            'lineItems' => $estimate->items->map(fn (EstimateItem $item) => [
                'id' => $item->id,
                'category' => $item->category,
                'description' => $item->description,
                'unit' => $item->unit,
                'quantity' => (float) $item->quantity,
                'unitCost' => (float) $item->unit_cost,
                'total' => (float) $item->total,
                'source' => $item->source,
                'pricingSource' => $item->pricing_source,
                'pricingConfidence' => $item->pricing_confidence,
            ])->all(),
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
     * Header fields — mobile's counterpart to web's own
     * `EstimateDetailController::update()`. The project/client an estimate
     * is on isn't editable here (that's a reassignment, not an edit — no
     * mobile picker for it this pass); everything else uses the exact same
     * validation and recalculation web does.
     */
    public function update(Request $request, Estimate $estimate): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $estimate->user_id), 403);

        $userIds = Ownership::userIdList($request->user());

        $validated = $request->validate([
            // What the estimate is on / who it is for — optional, and applied only
            // when sent (web's form always sends them). Must be this manager's own.
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->whereIn('user_id', $userIds)],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->whereIn('user_id', $userIds)],
            'status' => ['required', Rule::in(Estimate::STATUSES)],
            'issued_on' => ['required', 'date'],
            'markup_pct' => ['required', 'numeric', 'min:0', 'max:200'],
            'tax_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // The two name snapshots the record prints from are read from the
        // project, never trusted from the form — so the record cannot name one
        // client and point at another one's project.
        if ($request->has('project_id')) {
            $project = Project::whereIn('user_id', $userIds)->with('clientRecord:id,name')->find($validated['project_id'] ?? null);
            $validated['client_id'] = $project?->client_id;
            $validated['client'] = $project?->clientRecord?->name ?? $project?->client ?? 'Unassigned';
            $validated['project'] = $project?->name ?? $validated['client'];
        } else {
            unset($validated['project_id'], $validated['client_id']);
        }

        $estimate->update($validated);
        $estimate->recalculateTotals();
        app(EstimateBuilder::class)->syncJobBudget($estimate);

        $estimate->aiResult?->recordHistory(
            'estimate_updated',
            "Estimate {$estimate->number} updated",
            to: '$'.number_format((float) $estimate->fresh()->grand_total, 2),
        );

        return $this->ok(['id' => $estimate->id], "Estimate {$estimate->number} updated.");
    }

    /**
     * `GET /estimates/{estimate}/edit-options` — what the Edit form's Client and
     * Project pickers offer: this manager's own clients and projects, with the
     * estimate's current ones (the client falls back to its project's).
     */
    public function editOptions(Request $request, Estimate $estimate): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $estimate->user_id), 403);

        $estimate->loadMissing('takeoffProject');

        return $this->ok([
            'clientId' => $estimate->client_id ?? $estimate->takeoffProject?->client_id,
            'projectId' => $estimate->project_id,
            'clientName' => $estimate->client,
            'clients' => Client::whereIn('user_id', Ownership::userIdList($request->user()))
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn ($client) => ['id' => $client->id, 'name' => $client->name])->values(),
            'projects' => app(ProjectDirectory::class)->options($request->user())
                ->map(fn ($project) => ['id' => $project['id'], 'clientId' => $project['clientId'], 'name' => $project['name']])
                ->values(),
        ]);
    }

    /**
     * One labor rate for the whole estimate — web's `updateLaborRate`. Re-prices
     * every labor line here and on its addenda, and the totals follow.
     */
    public function updateLaborRate(Request $request, Estimate $estimate): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $estimate->user_id), 403);
        abort_if($estimate->builder_managed && $estimate->status !== 'draft', 409, 'This estimate is with review and approval and can no longer be edited here.');

        $validated = $request->validate([
            'labor_rate' => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);

        $rate = round((float) $validated['labor_rate'], 2);

        collect([$estimate])->merge($estimate->addenda)->each(function (Estimate $target) use ($rate) {
            $target->items()->where('category', EstimateItem::CATEGORY_LABOR)->get()
                ->each(fn (EstimateItem $item) => $item->update(['unit_cost' => $rate]));

            $target->recalculateTotals();
            app(EstimateBuilder::class)->syncJobBudget($target);
        });

        $estimate->aiResult?->recordHistory(
            'estimate_labor_rate_updated',
            "Labor rate set to $".number_format($rate, 2)." on estimate {$estimate->number}",
        );

        return $this->ok(['laborRate' => $rate], 'Labor rate set to $'.number_format($rate, 2).' on every labor line.');
    }

    /**
     * The one rate every labor line (addenda included) already carries when they
     * all agree; otherwise the client's own rate, or the configured default.
     */
    private function currentLaborRate(Estimate $estimate): float
    {
        $rates = EstimateItem::query()
            ->where('category', EstimateItem::CATEGORY_LABOR)
            ->whereIn('estimate_id', collect([$estimate->id])->merge($estimate->addenda()->pluck('id')))
            ->pluck('unit_cost')
            ->map(fn ($rate) => round((float) $rate, 2))
            ->unique();

        return $rates->count() === 1
            ? (float) $rates->first()
            : ($estimate->clientRecord?->effectiveLaborRate() ?? (float) config('ai.estimating.labor_rate'));
    }

    /** `POST /estimates/{estimate}/items` — a manual line, same fields web's form takes. */
    public function storeItem(Request $request, Estimate $estimate): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $estimate->user_id), 403);

        $validated = $this->validatedItem($request);

        $item = $estimate->items()->create([
            ...$validated,
            'source' => 'manual',
            'position' => (int) $estimate->items()->max('position') + 1,
        ]);

        $estimate->recalculateTotals();
        app(EstimateBuilder::class)->syncJobBudget($estimate);

        $estimate->aiResult?->recordHistory(
            'estimate_line_added',
            "Added \"{$item->description}\" to estimate {$estimate->number}",
            to: '$'.number_format((float) $item->total, 2),
        );

        return $this->created(['id' => $item->id], "\"{$item->description}\" added.");
    }

    public function updateItem(Request $request, Estimate $estimate, EstimateItem $item): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $estimate->user_id), 403);
        abort_unless($item->estimate_id === $estimate->id, 404);

        $validated = $this->validatedItem($request);

        $before = (float) $item->total;
        $item->update($validated);
        $estimate->recalculateTotals();
        app(EstimateBuilder::class)->syncJobBudget($estimate);

        $estimate->aiResult?->recordHistory(
            'estimate_line_updated',
            "Updated \"{$item->description}\" on estimate {$estimate->number}",
            from: '$'.number_format($before, 2),
            to: '$'.number_format((float) $item->fresh()->total, 2),
        );

        return $this->ok(['id' => $item->id], "\"{$item->description}\" updated.");
    }

    public function destroyItem(Request $request, Estimate $estimate, EstimateItem $item): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $estimate->user_id), 403);
        abort_unless($item->estimate_id === $estimate->id, 404);

        $description = $item->description;
        $item->delete();
        $estimate->recalculateTotals();
        app(EstimateBuilder::class)->syncJobBudget($estimate);

        $estimate->aiResult?->recordHistory(
            'estimate_line_removed',
            "Removed \"{$description}\" from estimate {$estimate->number}",
        );

        return $this->ok(null, "\"{$description}\" removed.");
    }

    /** @return array<string, mixed> */
    private function validatedItem(Request $request): array
    {
        return $request->validate([
            'category' => ['required', Rule::in(EstimateItem::CATEGORIES)],
            'description' => ['required', 'string', 'max:200'],
            'unit' => ['required', 'string', 'max:12'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'unit_cost' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);
    }
}
