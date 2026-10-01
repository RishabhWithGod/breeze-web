<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProjectSummaryResource;
use App\Models\Project;
use App\Support\Ownership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TakeoffHistoryController extends Controller
{
    /** Search, status filter, sort and pagination all run in the database. */
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([
                'all', 'processing', 'ready-for-review', 'completed', 'converted',
            ])],
            'client' => ['nullable', 'string', 'max:160'],
            // Not an id-shaped rule: 'all' is the honest default here too, the
            // same way it is for status and client.
            'project' => ['nullable', 'string', 'max:20'],
            'sort' => ['nullable', Rule::in(['date-desc', 'date-asc', 'name-asc'])],
        ]);

        $status = $filters['status'] ?? 'all';
        $client = $filters['client'] ?? 'all';
        $project = $filters['project'] ?? 'all';
        $sort = $filters['sort'] ?? 'date-desc';

        $projects = $this->finished($request)
            ->search($filters['search'] ?? null)
            ->when($status !== 'all', function ($query) use ($status) {
                // Not a stored column — it is `review_status` read a different
                // way, so it gets its own branch rather than a plain equals.
                if ($status === 'ready-for-review') {
                    return $query->whereIn('review_status', ['pending', 'in-review']);
                }

                return $query->where('status', $status);
            })
            ->when($client !== 'all', fn ($query) => $query->where('client', $client))
            ->when($project !== 'all', fn ($query) => $query->whereKey((int) $project))
            ->tap(fn ($query) => match ($sort) {
                'date-asc' => $query->oldest(),
                'name-asc' => $query->orderBy('name'),
                default => $query->latest(),
            })
            ->paginate(config('takeoff.per_page'))
            ->withQueryString();

        return Inertia::render('History', [
            'projects' => ProjectSummaryResource::collection($projects),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $status,
                'client' => $client,
                'project' => $project,
                'sort' => $sort,
            ],
            // Every one of this user's own clients and projects, for the
            // filter dropdowns — real options, not a guess at what exists.
            'clients' => $this->finished($request)
                ->whereNotNull('client')
                ->distinct()
                ->orderBy('client')
                ->pluck('client'),
            'projectOptions' => $this->finished($request)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * The takeoffs that are under way or done. A project whose takeoff was never run (a draft) or
     * did not finish (failed) is not a takeoff yet, so it is not listed.
     */
    private function finished(Request $request)
    {
        return Project::query()
            ->whereIn('user_id', Ownership::userIds($request->user()))
            ->whereIn('status', ['processing', 'completed', 'converted']);
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        abort_unless(Ownership::owns($request->user(), $project->user_id), 403);

        $project->delete();

        return back()->with('warning', "“{$project->name}” was deleted.");
    }

    /** Undo for the delete above — the row is soft deleted, so it comes back. */
    public function restore(Request $request, int $project): RedirectResponse
    {
        $trashed = Project::onlyTrashed()->findOrFail($project);

        abort_unless(Ownership::owns($request->user(), $trashed->user_id), 403);

        $trashed->restore();

        return back()->with('success', "“{$trashed->name}” was restored.");
    }
}
