<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\ClientAddress;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\Project;
use App\Services\Takeoff\TakeoffFlow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        abort_unless($project->user_id === $request->user()->id, 403);

        $project->load(['addresses', 'jobs.foreman:id,name,initials,role', 'estimates']);
        $project->loadCount(['uploads', 'aiResults']);

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
        ]);
    }

    /**
     * Opens a project — the start of the AI Takeoff flow. Matches web's own
     * `ProjectController::store()` exactly (no address on the form, the
     * client's primary site is snapshotted; the drawing itself isn't
     * accepted here, only through the upload step that follows).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'estimate_target_total' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);

        $client = $request->user()->clients()->with('primaryAddress')->findOrFail($data['client_id']);
        $site = $client->primaryAddress;

        $project = $request->user()->projects()->create([
            'client_id' => $client->id,
            'name' => $data['name'],
            'client' => $client->name,
            'location' => $site?->address,
            'latitude' => $site?->latitude,
            'longitude' => $site?->longitude,
            'place_id' => $site?->place_id,
            'status' => 'draft',
            'review_status' => 'none',
            'estimate_target_total' => $data['estimate_target_total'] ?? null,
        ]);

        app(TakeoffFlow::class)->remember($project);

        $project->activities()->create([
            'title' => 'Project opened',
            'description' => 'No drawings yet — upload one from AI Takeoff',
            'tone' => 'brand',
            'occurred_at' => now(),
        ]);

        return $this->created([
            'id' => $project->id,
            'name' => $project->name,
            'clientId' => $project->client_id,
            'client' => $project->client,
            'status' => $project->status,
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
    public function update(Request $request, Project $project): JsonResponse
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'estimate_target_total' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);

        $client = $request->user()->clients()->with('primaryAddress')->findOrFail($data['client_id']);
        $site = $client->primaryAddress;

        $project->update([
            'client_id' => $client->id,
            'name' => $data['name'],
            'client' => $client->name,
            'location' => $site?->address,
            'latitude' => $site?->latitude,
            'longitude' => $site?->longitude,
            'place_id' => $site?->place_id,
            'estimate_target_total' => $data['estimate_target_total'] ?? null,
        ]);

        return $this->ok([
            'id' => $project->id,
            'name' => $project->name,
            'clientId' => $project->client_id,
            'client' => $project->client,
            'status' => $project->status,
        ], "\"{$project->name}\" was updated.");
    }
}
