<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProjectSummaryResource;
use App\Models\Project;
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
            'status' => ['nullable', Rule::in(['all', 'draft', 'completed', 'converted'])],
            'sort' => ['nullable', Rule::in(['date-desc', 'date-asc', 'name-asc'])],
        ]);

        $status = $filters['status'] ?? 'all';
        $sort = $filters['sort'] ?? 'date-desc';

        $projects = Project::query()
            ->where('user_id', $request->user()->id)
            ->search($filters['search'] ?? null)
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
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
                'sort' => $sort,
            ],
        ]);
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $project->delete();

        return back()->with('warning', "“{$project->name}” was deleted.");
    }

    /** Undo for the delete above — the row is soft deleted, so it comes back. */
    public function restore(Request $request, int $project): RedirectResponse
    {
        $trashed = Project::onlyTrashed()->findOrFail($project);

        abort_unless($trashed->user_id === $request->user()->id, 403);

        $trashed->restore();

        return back()->with('success', "“{$trashed->name}” was restored.");
    }
}
