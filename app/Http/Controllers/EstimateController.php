<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEstimateRequest;
use App\Http\Resources\EstimateResource;
use App\Models\Estimate;
use App\Models\Project;
use App\Models\Upload;
use App\Services\Clients\ClientDirectory;
use App\Services\Clients\ProjectDirectory;
use App\Services\Takeoff\TakeoffLinkOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EstimateController extends Controller
{
    public function __construct(
        private readonly TakeoffLinkOptions $linkOptions,
        private readonly ClientDirectory $clients,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', ...Estimate::STATUSES])],
            'client' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'sort' => ['nullable', Rule::in(Estimate::SORTS)],
        ]);

        $status = $filters['status'] ?? 'all';
        $client = $filters['client'] ?? 'all';
        $sort = $filters['sort'] ?? 'date-desc';

        $estimates = Estimate::query()
            ->ownedBy($request->user())
            // An addendum is never its own row here — it belongs to, and is
            // only ever shown from, the original estimate it adds scope to.
            ->where('kind', '!=', Estimate::KIND_ADDENDUM)
            ->search($filters['search'] ?? null)
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($client !== 'all', fn ($query) => $query->where('client', $client))
            ->issuedBetween($filters['date_from'] ?? null, $filters['date_to'] ?? null)
            ->sorted($sort)
            ->paginate(config('takeoff.per_page'))
            ->withQueryString();

        return Inertia::render('Estimates', [
            'estimates' => EstimateResource::collection($estimates),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $status,
                'client' => $client,
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
                'sort' => $sort,
            ],
            // Drives the Client dropdown; kept in sync with whatever is stored.
            'clients' => Estimate::query()
                ->ownedBy($request->user())
                ->where('kind', '!=', Estimate::KIND_ADDENDUM)
                ->distinct()
                ->orderBy('client')
                ->pluck('client'),
        ]);
    }

    /** Full-page create form. */
    public function create(Request $request): Response
    {
        return Inertia::render('EstimateCreate', [
            'nextNumber' => Estimate::nextNumber($request->user()),
            'clients' => $this->clients->options($request->user()),
            // Their projects, and their drawings — each narrows the next.
            'projects' => app(ProjectDirectory::class)->options($request->user()),
            'uploads' => $this->linkOptions->uploads(),
        ]);
    }

    public function store(StoreEstimateRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $upload = isset($data['upload_id']) ? Upload::find($data['upload_id']) : null;
        $aiResult = $upload?->latestAiResult;

        // The PDF already has an estimate — nothing to create, only to open.
        if ($aiResult?->estimate) {
            return redirect()
                ->route('estimates.edit', $aiResult->estimate)
                ->with('warning', "{$upload->label()} already has estimate {$aiResult->estimate->number}.");
        }

        unset($data['upload_id']);

        /*
         * The two name snapshots the record prints from: who it is for, and
         * what it is on. Both are read from the project rather than trusted
         * from the form, so the record cannot name one client and point at
         * another one's project — and a project with no client record yet falls
         * back to the name it carries, because neither column may be empty.
         */
        $project = Project::where('user_id', $request->user()->id)->with('clientRecord:id,name')->find($data['project_id']);
        $data['client_id'] = $project?->client_id;
        $data['client'] = $project?->clientRecord?->name ?? $project?->client ?? 'Unassigned';
        $data['project'] = $project?->name ?? $data['client'];

        $estimate = Estimate::create([
            ...$data,
            'ai_result_id' => $aiResult?->id,
            'number' => Estimate::nextNumber($request->user()),
        ]);

        if ($aiResult) {
            $aiResult->update(['estimate_id' => $estimate->id]);
        }

        return redirect()
            ->route('estimates.index')
            ->with('success', "{$estimate->number} was created.");
    }

    public function destroy(Estimate $estimate): RedirectResponse
    {
        $this->authorize('delete', $estimate);

        $estimate->delete();

        return back()->with('warning', "{$estimate->number} was deleted.");
    }

    /** Undo for the delete above. */
    public function restore(Request $request, int $estimate): RedirectResponse
    {
        $trashed = Estimate::onlyTrashed()->ownedBy($request->user())->findOrFail($estimate);
        $trashed->restore();

        return back()->with('success', "{$trashed->number} was restored.");
    }
}
