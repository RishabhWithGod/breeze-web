<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\Document;
use App\Models\Estimate;
use App\Models\Foreman;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Team;
use App\Services\Clients\ClientDirectory;
use App\Services\Estimating\ProjectRateBookImporter;
use App\Services\Takeoff\TakeoffFlow;
use App\Services\Teams\ResolveInlineTeam;
use App\Support\CompanyRule;
use App\Support\Ownership;
use App\Support\UploadLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Projects, as the mobile app sees them — only the signed-in user's own
 * projects (`Project::scopeOwnedBy`, same shape as `Estimate`/`Job`),
 * most-recently-touched first: web's own index (`ProjectController::index`)
 * sorts its nested per-client project lists by `updated_at` desc, `id`
 * desc, not `created_at` — mirrored here exactly rather than falling back
 * to `Project::scopeSorted()`'s own default, which web's index doesn't
 * actually use.
 *
 * No server-side search/status filter yet, matching the mobile Jobs/
 * Estimates endpoints' own precedent (client-side filtering for now).
 */
class ProjectController extends Controller
{
    use ApiResponses;

    public function index(Request $request): JsonResponse
    {
        $projects = Project::query()
            ->ownedBy($request->user())
            ->withCount(['uploads', 'aiResults', 'jobs', 'estimates'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return $this->ok([
            'projects' => $projects->getCollection()->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'code' => $project->code,
                'clientId' => $project->client_id,
                'client' => $project->client,
                'location' => $project->location,
                'notes' => $project->notes,
                'status' => $project->status,
                'projectType' => $project->project_type,
                'documentsCount' => (int) $project->uploads_count,
                'takeoffCount' => (int) $project->ai_results_count,
                'jobCount' => (int) $project->jobs_count,
                'estimateCount' => (int) $project->estimates_count,
                'createdAt' => $project->created_at?->toISOString(),
                'updatedAt' => $project->updated_at?->toISOString(),
            ])->all(),
            'meta' => [
                'currentPage' => $projects->currentPage(),
                'lastPage' => $projects->lastPage(),
                'perPage' => $projects->perPage(),
                'total' => $projects->total(),
            ],
        ]);
    }

    /**
     * The detail screen's shape — the client's real address book
     * (`addresses()`, `HasManyThrough` off `Client`) rather than `index`'s
     * single snapshotted `location` string, plus the jobs/estimates raised
     * for this project. Job entries use the exact same field names as
     * `JobController`'s own summary shape so the app's existing `Job` model
     * parses them without a second shape to maintain.
     */
    public function show(Request $request, Project $project): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $project->user_id), 403);

        $project->load([
            'addresses', 'jobs.foreman:id,name,initials,role', 'estimates',
            'members', 'user', 'invoices', 'documents', 'latestAiResult',
        ]);
        $project->loadCount(['uploads', 'aiResults', 'estimates', 'jobs', 'members', 'documents', 'invoices']);

        return $this->ok([
            'id' => $project->id,
            'name' => $project->name,
            'code' => $project->code,
            'clientId' => $project->client_id,
            'client' => $project->client,
            'location' => $project->location,
            'notes' => $project->notes,
            'status' => $project->status,
            'projectType' => $project->project_type,
            'documentsCount' => (int) $project->uploads_count,
            'takeoffCount' => (int) $project->ai_results_count,
            'estimateTargetTotal' => $project->estimate_target_total === null
                ? null
                : (float) $project->estimate_target_total,
            'createdAt' => $project->created_at?->toISOString(),
            'updatedAt' => $project->updated_at?->toISOString(),
            'addresses' => $project->addresses->map(fn (ClientAddress $address) => [
                'id' => $address->id,
                'label' => $address->label,
                'address' => $address->address,
                'siteType' => $address->site_type,
                'isPrimary' => (bool) $address->is_primary,
            ])->all(),
            'jobs' => $project->jobs->map(fn (Job $job) => [
                'id' => $job->id,
                'name' => $job->name,
                'client' => $job->client,
                'location' => $job->location,
                'latitude' => $job->latitude === null ? null : (float) $job->latitude,
                'longitude' => $job->longitude === null ? null : (float) $job->longitude,
                'geofenceRadius' => $job->geofence_radius,
                'placeId' => $job->place_id,
                'jobType' => $job->job_type,
                'status' => $job->status,
                'priority' => $job->priority,
                'startDate' => $job->start_date?->toDateString(),
                'endDate' => $job->end_date?->toDateString(),
                'foreman' => $job->foreman ? [
                    'name' => $job->foreman->name,
                    'initials' => $job->foreman->initials,
                    'role' => $job->foreman->roleLabel(),
                ] : null,
            ])->all(),
            'estimates' => $project->estimates->map(fn (Estimate $estimate) => [
                'id' => $estimate->id,
                'number' => $estimate->number,
                'client' => $estimate->client,
                'project' => $estimate->project,
                'issuedOn' => $estimate->issued_on->toDateString(),
                'amount' => (float) $estimate->amount,
                'status' => $estimate->status,
            ])->all(),
            // --- Project workspace (web's tabs) -------------------------------
            'description' => $project->description,
            // Who raised it — the only user a project is ever tied to.
            'owner' => $project->user?->name,
            'drawingName' => $project->drawing_name,
            'pageCount' => $project->page_count,
            'startedAt' => $project->started_at?->toISOString(),
            'dueDate' => $project->due_date?->toDateString(),
            'completedAt' => $project->completed_at?->toISOString(),
            'drawingCount' => $project->uploads_count,
            'estimateCount' => $project->estimates_count,
            'jobCount' => $project->jobs_count,
            'teamCount' => $project->members_count,
            'documentCount' => $project->documents_count,
            'invoiceCount' => $project->invoices_count,
            'latestTakeoffAt' => $project->latestAiResult?->created_at?->toISOString(),
            // Who from the client's crew is staffed to it.
            'members' => $project->members->map(fn (Foreman $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'initials' => $member->initials,
                'role' => $member->role,
                'roleLabel' => $member->roleLabel(),
                'phone' => $member->phone,
                'email' => $member->email,
            ])->values()->all(),
            'memberIds' => $project->members->pluck('id')->all(),
            'activity' => $project->activities()->limit(5)->get()->map(fn (ProjectActivity $entry) => [
                'id' => $entry->id,
                'title' => $entry->title,
                'description' => $entry->description,
                'tone' => $entry->tone,
                'occurredAt' => $entry->occurred_at?->toISOString(),
            ])->values()->all(),
            'drawings' => $project->uploads()->oldest()->get()->map(fn ($upload) => [
                'id' => $upload->id,
                'name' => $upload->title ?: $upload->name,
                'format' => $upload->format,
                'sizeBytes' => $upload->size_bytes,
                'pageCount' => $upload->page_count,
                'uploadedAt' => $upload->created_at?->toISOString(),
            ])->all(),
            'invoices' => $project->invoices->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'invoiceNumber' => $invoice->invoice_number,
                'status' => $invoice->status,
                'total' => (float) $invoice->total,
                'invoiceDate' => $invoice->invoice_date?->toDateString(),
                'dueDate' => $invoice->due_date?->toDateString(),
            ])->values()->all(),
            'documents' => $project->documents->map(fn (Document $document) => [
                'id' => $document->id,
                'name' => $document->name,
                'documentType' => $document->document_type,
                'fileSizeBytes' => $document->file_size,
                'createdAt' => $document->created_at?->toISOString(),
            ])->values()->all(),
            'commodityDocuments' => $project->documents
                ->where('document_type', Document::TYPE_COMMODITY_LIST)
                ->map(fn (Document $document) => [
                    'id' => $document->id,
                    'name' => $document->name,
                    'fileSizeBytes' => $document->file_size,
                    'createdAt' => $document->created_at?->toISOString(),
                ])->values()->all(),
        ]);
    }

    /**
     * `GET /projects/create-options` — web's `ProjectController::create()` props:
     * every client (with its sites, members and team) so the form can narrow
     * itself, and the teams for the Team field.
     */
    public function createOptions(Request $request): JsonResponse
    {
        return $this->ok([
            'clients' => app(ClientDirectory::class)->options($request->user())->values(),
            'teams' => Team::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($team) => ['id' => $team->id, 'name' => $team->name])->values(),
        ]);
    }

    /** `DELETE /projects/{project}` — soft delete, as web does. */
    public function destroy(Request $request, Project $project): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $project->user_id), 403);

        $project->delete();

        return $this->ok(null, "\"{$project->name}\" was deleted.");
    }

    /**
     * Opens a project — the start of the AI Takeoff flow. Matches web's own
     * `ProjectController::store()` exactly (no address on the form, the
     * client's primary site is snapshotted; the drawing itself isn't
     * accepted here, only through the upload step that follows).
     */
    public function store(Request $request, ProjectRateBookImporter $importer): JsonResponse
    {
        $data = $this->validatedProject($request);

        $client = $request->user()->clients()->with('primaryAddress')->findOrFail($data['client_id']);

        // Which crew normally works this client's sites — the same field Edit Client's Team sets.
        $client->update(['team_id' => $data['team_id'] ?? null]);

        // The project's location is the client's primary site, snapshotted.
        $site = $client->primaryAddress;

        $project = $request->user()->projects()->create([
            'client_id' => $client->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'client' => $client->name,
            'location' => $site?->address,
            'latitude' => $site?->latitude,
            'longitude' => $site?->longitude,
            'place_id' => $site?->place_id,
            'project_type' => $data['project_type'] ?? null,
            'started_at' => $data['start_date'] ?? null,
            'due_date' => $data['end_date'] ?? null,
            'status' => 'draft',
            'review_status' => 'none',
            'estimate_target_total' => $data['estimate_target_total'] ?? null,
        ]);

        // Staffed only from the client's own crew, never trusting whatever ids were posted.
        $project->members()->sync($this->eligibleMemberIds($client, $data['member_ids'] ?? []));

        app(TakeoffFlow::class)->remember($project, $request->user());

        $project->activities()->create([
            'title' => 'Project opened',
            'description' => 'No drawings yet — upload one from AI Takeoff',
            'tone' => 'brand',
            'occurred_at' => now(),
        ]);

        $warning = $request->hasFile('vendor_rate_list')
            ? $this->importVendorRateLists($request, $importer, $project)
            : null;

        return $this->created([
            'id' => $project->id,
            'name' => $project->name,
            'clientId' => $project->client_id,
            'client' => $project->client,
            'status' => $project->status,
            'warning' => $warning,
        ], "\"{$project->name}\" created.");
    }

    /**
     * `Api\V1\ProjectController::update()` — mirrors web's own
     * `ProjectController::update()`/`UpdateProjectRequest` exactly: the
     * same three fields `store()` takes (name/client/budget), nothing more.
     * Reassigning the client re-snapshots the site from that client's own
     * primary address, same as `store()` — never a second copy of the
     * client's address book left to drift.
     */
    public function update(Request $request, Project $project, ProjectRateBookImporter $importer): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $project->user_id), 403);

        $data = $this->validatedProject($request);

        $client = $request->user()->clients()->with('primaryAddress')->findOrFail($data['client_id']);
        $client->update(['team_id' => $data['team_id'] ?? null]);

        $site = $client->primaryAddress;

        $project->update([
            'client_id' => $client->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'client' => $client->name,
            'location' => $site?->address,
            'latitude' => $site?->latitude,
            'longitude' => $site?->longitude,
            'place_id' => $site?->place_id,
            'project_type' => $data['project_type'] ?? null,
            'started_at' => $data['start_date'] ?? null,
            'due_date' => $data['end_date'] ?? null,
            'estimate_target_total' => $data['estimate_target_total'] ?? null,
        ]);

        // Re-scoped to whatever client this project now belongs to.
        $project->members()->sync($this->eligibleMemberIds($client, $data['member_ids'] ?? []));

        $warning = $request->hasFile('vendor_rate_list')
            ? $this->importVendorRateLists($request, $importer, $project)
            : null;

        return $this->ok([
            'id' => $project->id,
            'name' => $project->name,
            'clientId' => $project->client_id,
            'client' => $project->client,
            'status' => $project->status,
            'warning' => $warning,
        ], "\"{$project->name}\" was updated.");
    }

    /**
     * The same rules whether the project is being opened or corrected —
     * web's `StoreProjectRequest` / `UpdateProjectRequest`.
     *
     * @return array<string, mixed>
     */
    private function validatedProject(Request $request): array
    {
        if ($request->input('estimate_target_total') === '') {
            $request->merge(['estimate_target_total' => null]);
        }

        // A team typed into the picker but not yet on the register — created now, only because this is saved.
        $request->merge(['team_id' => app(ResolveInlineTeam::class)($request->input('team_id'))]);

        $hasMembers = count(array_filter((array) $request->input('member_ids', []))) > 0;

        return $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'description' => ['nullable', 'string', 'max:255'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            // Someone actually picked to staff this project has to land on a real team.
            'team_id' => [...($hasMembers ? ['required'] : ['nullable']), 'integer', CompanyRule::exists('teams')],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer', CompanyRule::exists('foremen')],
            'project_type' => ['nullable', Rule::in(Project::TYPES)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'estimate_target_total' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'vendor_rate_list' => ['nullable', 'array', 'max:20'],
            'vendor_rate_list.*' => ['file', 'mimes:xlsx,ods,csv,txt,pdf,doc,docx,rtf,odt', 'max:'.(UploadLimits::effectiveMb() * 1024)],
        ], [
            'name.required' => 'Project name is required',
            'client_id.required' => 'Pick the client this project is for',
            'team_id.required' => 'Pick a team for the members you selected',
            'name.min' => 'Use at least 3 characters',
            'end_date.after_or_equal' => 'End date cannot be before the start date',
            'estimate_target_total.numeric' => 'Enter a valid amount',
            'estimate_target_total.min' => 'Amount cannot be negative',
            'vendor_rate_list.max' => 'Upload up to 20 files at a time',
            'vendor_rate_list.*.mimes' => 'Upload an Excel, CSV, PDF, or Word (.doc/.docx) commodity list',
            'vendor_rate_list.*.max' => 'Each file must be under '.UploadLimits::effectiveMb().' MB',
        ]);
    }

    /** Only people from the client's own crew (its members, or its team) may be staffed. */
    private function eligibleMemberIds(Client $client, array $memberIds): \Illuminate\Support\Collection
    {
        $clientMemberIds = $client->members()->pluck('foremen.id');

        $teamMemberIds = $client->team_id === null
            ? collect()
            : Foreman::query()->where('team_id', $client->team_id)->pluck('id');

        return $clientMemberIds->merge($teamMemberIds)->unique()->intersect($memberIds)->values();
    }

    /**
     * The project's own rates, folded into its own rate book. A bad or
     * unreadable file must not stop the project opening, so this only ever
     * returns a warning.
     */
    private function importVendorRateLists(Request $request, ProjectRateBookImporter $importer, Project $project): ?string
    {
        $files = collect($request->file('vendor_rate_list'))
            ->map(fn ($file) => ['file' => $file, 'name' => $file->getClientOriginalName()])
            ->all();

        $result = $importer->importWorkbooks($files, $project->id);

        $notes = [];

        if ($result['failed'] !== []) {
            $notes[] = implode(', ', $result['failed'])." couldn't be read as a rate list.";
        }

        if ($result['empty'] !== []) {
            $notes[] = implode(', ', $result['empty']).' had no priced lines the importer could read.';
        }

        return $notes === [] ? null : implode(' ', $notes);
    }
}
