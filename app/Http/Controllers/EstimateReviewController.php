<?php

namespace App\Http\Controllers;

use App\Models\Estimate;
use App\Models\EstimateRevision;
use App\Notifications\EstimateStatusChanged;
use App\Services\Estimating\EstimateApprovals;
use App\Support\Ownership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Estimate Review and Approval: verify the scope and authorize the estimate for use.
 *
 * A Project Manager, Estimator or Supervisor reviews an estimate that has been sent
 * and either approves it — which locks the revision and records who and when — or
 * returns it for edits with notes. A foreman only ever sees an approved estimate.
 */
class EstimateReviewController extends Controller
{
    public function __construct(private readonly EstimateApprovals $approvals) {}

    public function show(Request $request, Estimate $estimate): Response|RedirectResponse
    {
        $this->authorizeView($request, $estimate);

        // Nothing to review until it has been sent, and nothing more once it was turned down.
        if (! in_array($estimate->status, ['sent', 'approved'], true)) {
            return $estimate->builder_managed && $estimate->status === 'draft'
                ? redirect()->route('estimate-builder.show', $estimate)
                : redirect()->route('estimates.show', $estimate);
        }

        $estimate->load(['clientRecord.primaryAddress', 'takeoffProject', 'approver:id,name', 'reviewer:id,name', 'revisions.author:id,name', 'items', 'builderLines']);

        $project = $estimate->takeoffProject;
        $summary = $this->approvals->lineSummary($estimate);

        return Inertia::render('EstimateReview', [
            'estimate' => [
                'id' => $estimate->id,
                'number' => $estimate->number,
                'status' => $estimate->status,
                'builderManaged' => $estimate->builder_managed,
            ],
            'client' => [
                'name' => $estimate->clientRecord?->name ?? $estimate->client,
                'address' => $estimate->clientRecord?->primaryAddress?->address,
            ],
            'project' => [
                'name' => $estimate->project,
                'detail' => collect([$estimate->number, $project?->project_type ? ucfirst($project->project_type) : null])->filter()->implode('  |  '),
            ],
            'scopeOfWork' => $estimate->scope_of_work,
            'exclusions' => $estimate->exclusions ?? [],
            'summary' => $summary,
            'revisions' => $estimate->revisions->map(fn (EstimateRevision $revision) => [
                'version' => $revision->version,
                'date' => $revision->created_at?->toISOString(),
                'changes' => $revision->changes,
                'by' => $revision->author?->name,
                'total' => (float) $revision->total,
                'approved' => $estimate->status === 'approved' && $estimate->approved_revision === $revision->version,
            ])->values(),
            'approval' => $estimate->status === 'approved' ? [
                'by' => $estimate->approver?->name,
                'at' => $estimate->approved_at?->toISOString(),
                'revision' => $estimate->approved_revision,
                'notes' => $estimate->review_notes,
            ] : null,
            'canDecide' => $estimate->status === 'sent' && $this->isReviewer($request),
            // Once approved: on to a job, or out as a file.
            'jobUrl' => $estimate->status === 'approved'
                ? ($estimate->job_id ? route('jobs.show', $estimate->job_id, absolute: false) : ($estimate->project_id ? route('addenda.index', ['project' => $estimate->project_id], absolute: false) : null))
                : null,
            'hasJob' => $estimate->job_id !== null,
            'exports' => $estimate->status === 'approved' ? [
                'pdf' => route('estimates.pdf', $estimate, absolute: false),
                'csv' => route('estimates.export.csv', $estimate, absolute: false),
            ] : null,
        ]);
    }

    public function approve(Request $request, Estimate $estimate): RedirectResponse
    {
        $this->authorizeDecision($request, $estimate);

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        $this->approvals->approve($estimate, $request->user(), $data['notes'] ?? null);
        $this->tell($request, $estimate, EstimateStatusChanged::APPROVED);

        return back()->with('success', "{$estimate->number} was approved and its revision is locked.");
    }

    public function returnForEdits(Request $request, Estimate $estimate): RedirectResponse
    {
        $this->authorizeDecision($request, $estimate);

        $data = $request->validate(
            ['notes' => ['required', 'string', 'min:3', 'max:1000']],
            ['notes.required' => 'Say what needs to change, so it can be fixed.', 'notes.min' => 'Say what needs to change, so it can be fixed.'],
        );

        $this->approvals->returnForEdits($estimate, $request->user(), $data['notes']);
        $this->tell($request, $estimate, EstimateStatusChanged::RETURNED);

        return redirect()->route('estimates.index')->with('warning', "{$estimate->number} was returned for edits.");
    }

    /** The estimate's owner hears the decision, unless they made it. */
    private function tell(Request $request, Estimate $estimate, string $decision): void
    {
        $owner = $estimate->owner;

        if ($owner && $owner->id !== $request->user()->id) {
            $owner->notify(new EstimateStatusChanged($estimate, $decision));
        }
    }

    private function isReviewer(Request $request): bool
    {
        return in_array(mb_strtolower(trim((string) $request->user()->role)), config('estimates.reviewer_roles'), true);
    }

    /** Reviewers see any estimate of their company that has been sent; a foreman only an approved one. */
    private function authorizeView(Request $request, Estimate $estimate): void
    {
        abort_unless(Ownership::owns($request->user(), $estimate->user_id), 403);

        $foreman = mb_strtolower(trim((string) $request->user()->role)) === 'foreman';

        abort_unless($this->isReviewer($request) || ($foreman && $estimate->status === 'approved'), 403);
    }

    private function authorizeDecision(Request $request, Estimate $estimate): void
    {
        abort_unless(Ownership::owns($request->user(), $estimate->user_id) && $this->isReviewer($request), 403);
        abort_unless($estimate->status === 'sent', 409, 'This estimate is not waiting for a decision.');
    }
}
