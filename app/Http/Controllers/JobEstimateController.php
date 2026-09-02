<?php

namespace App\Http\Controllers;

use App\Models\Estimate;
use App\Models\Job;
use App\Models\Project;
use App\Services\Takeoff\EstimateBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class JobEstimateController extends Controller
{
    /**
     * Raises an estimate for the job.
     *
     * A job that came from a reviewed takeoff is priced from that takeoff's final
     * JSON — the same path the takeoff screen uses — so the estimate arrives
     * carrying the engine's bill of quantities rather than empty. Only a job with no
     * takeoff behind it gets a bare estimate to be priced by hand.
     */
    public function store(Job $job, EstimateBuilder $builder): RedirectResponse
    {
        $result = $job->aiResult;

        if ($result?->isFinalised()) {
            try {
                $estimate = $builder->fromFinalJson($result, request()->user(), $job);
            } catch (RuntimeException $e) {
                return back()->with('warning', $e->getMessage());
            }

            return redirect()
                ->route('estimates.show', $estimate)
                ->with('success', "{$estimate->number} was generated from the reviewed takeoff.");
        }

        $estimate = Estimate::create([
            'job_id' => $job->id,
            'project_id' => $job->project_id,
            'number' => Estimate::nextNumber(),
            // Both name columns are the client's — see ClientDirectory.
            'client' => $job->client ?? 'Unassigned',
            'project' => $job->client ?? 'Unassigned',
            'issued_on' => now()->toDateString(),
            'amount' => $job->budget ?? 0,
            // Raised against a job that already exists — see Estimate::statusFor().
            'status' => Estimate::statusFor($job),
            'markup_pct' => (float) config('ai.estimating.markup_pct'),
            'tax_pct' => (float) config('ai.estimating.tax_pct'),
        ]);

        $job->recordActivity('estimate_created', "Estimate {$estimate->number} created", [
            'estimate_id' => $estimate->id,
            'number' => $estimate->number,
        ]);

        return redirect()
            ->route('estimates.show', $estimate)
            ->with('success', "{$estimate->number} was created for this job.");
    }

    /**
     * Converts an estimate into a takeoff project: creates the Project, links it
     * back to the estimate and marks the estimate converted.
     */
    public function convert(Job $job, Estimate $estimate): RedirectResponse
    {
        abort_unless($estimate->job_id === $job->id, 404);

        if ($estimate->isConverted()) {
            return back()->with('warning', "{$estimate->number} has already been converted.");
        }

        $project = Project::create([
            'user_id' => Auth::id(),
            // A client's name and its `client` column are the same string.
            'name' => $estimate->client,
            'client' => $estimate->client,
            'discipline' => 'Electrical',
            'status' => 'draft',
            'items_count' => 0,
            'page_count' => 0,
            'notes' => "Converted from estimate {$estimate->number}.",
        ]);

        $estimate->update([
            'status' => 'approved',
            'converted_project_id' => $project->id,
            'converted_at' => now(),
        ]);

        $job->recordActivity(
            'estimate_converted',
            "Estimate {$estimate->number} converted to project “{$project->name}”",
            ['estimate_id' => $estimate->id, 'project_id' => $project->id],
        );

        return back()->with(
            'success',
            "{$estimate->number} was converted into a project."
        );
    }
}
