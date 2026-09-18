<?php

namespace App\Http\Controllers;

use App\Models\Estimate;
use App\Models\Team;
use App\Services\Clients\ClientDirectory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where a project's addenda are managed: which standalone estimate they
 * belong to, their own totals, and which ones a new job should be raised from.
 *
 * Read-only selection screen — an addendum is *created* through the same
 * upload → AI takeoff → review flow every estimate is, never here (see
 * `UploadController::create()`'s `?addendum_for=` and
 * `EstimateBuilder::open()`). This screen only lists what already exists and
 * hands a chosen set of estimate ids to `JobFromEstimatesController`.
 */
class AddendumController extends Controller
{
    public function __construct(private readonly ClientDirectory $clients) {}

    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $projects = $request->user()->projects()
            ->with('clientRecord:id,name')
            ->orderByDesc('created_at')
            ->get(['id', 'client_id', 'name'])
            ->map(fn ($project) => [
                'id' => $project->id,
                'clientId' => $project->client_id,
                'name' => $project->name,
                'clientName' => $project->clientRecord?->name,
            ]);

        $selectedProjectId = $request->integer('project') ?: null;

        $originals = [];

        if ($selectedProjectId !== null && $projects->contains('id', $selectedProjectId)) {
            $originals = Estimate::query()
                ->where('user_id', $userId)
                ->where('project_id', $selectedProjectId)
                ->where('kind', Estimate::KIND_STANDALONE)
                ->with(['addenda' => fn ($query) => $query->where('user_id', $userId)])
                ->orderByDesc('issued_on')
                ->get()
                ->map(fn (Estimate $estimate) => $this->present($estimate, $estimate->addenda))
                ->all();
        }

        return Inertia::render('Addendum/Index', [
            'projects' => $projects,
            'selectedProjectId' => $selectedProjectId,
            'originals' => $originals,
            // The same client/address book Create Job uses — the "create a job
            // from selected estimates" form on this screen picks a site the
            // same way.
            'clients' => $this->clients->options($request->user()),
            'teams' => Team::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Estimate $estimate, iterable $addenda = []): array
    {
        return [
            'id' => $estimate->id,
            'number' => $estimate->number,
            'addendumNumber' => $estimate->addendum_number,
            'addendumName' => $estimate->addendum_name,
            'status' => $estimate->status,
            'amount' => (float) $estimate->amount,
            'createdAt' => $estimate->created_at->toISOString(),
            'addenda' => collect($addenda)->map(fn (Estimate $addendum) => $this->present($addendum))->values(),
        ];
    }
}
