<?php

namespace App\Services\Takeoff;

use App\Events\EstimateGenerated;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Job;
use App\Models\User;
use App\Services\Clients\JobSites;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one place a job is raised from a set of selected estimates/addenda —
 * used by both `JobFromEstimatesController` (the Addendum screen's own
 * "Create Job") and `FinalTakeoffController::storeJob` (the same "Continue to
 * Job" roadmap step, reached from an estimate that already has addenda to
 * fold in). Two entry points, one implementation, so "which sources merge
 * into the new job" is answered exactly once.
 *
 * Every selected estimate is read, never mutated (beyond its own `status`,
 * once it has actually been put to work — see below): their lines are cloned
 * onto a brand-new `kind = merged` estimate on a brand-new job, so an
 * estimate already used by an earlier job, or reused by a later one, is
 * never taken from it, and no existing job is ever touched or reused.
 */
class EstimateMergeJobBuilder
{
    public function __construct(private readonly JobSites $sites) {}

    /**
     * @param  Collection<int, Estimate>  $sources  Already ownership- and kind-checked by the caller.
     * @param  array{name: string, team_id: int, address_ids: array<int>, start_date: string, end_date: string, description?: string|null, job_type?: string|null}  $attributes
     */
    public function build(Collection $sources, array $attributes, User $user): Job
    {
        $projectIds = $sources->pluck('project_id')->unique();
        abort_unless($projectIds->count() === 1, 422, 'Selected estimates must all be on the same project.');

        $addresses = $this->sites->resolve((int) $sources->first()->client_id, $attributes['address_ids'] ?? []);

        return DB::transaction(function () use ($sources, $attributes, $addresses, $user) {
            $original = $sources->firstWhere('kind', Estimate::KIND_STANDALONE) ?? $sources->first();

            $job = Job::create([
                'user_id' => $user->id,
                'project_id' => $original->project_id,
                'client_id' => $original->client_id,
                'client' => $original->client,
                'name' => $attributes['name'],
                'team_id' => $attributes['team_id'],
                'status' => 'planning',
                'description' => $attributes['description'] ?? null,
                'job_type' => $attributes['job_type'] ?? null,
                'start_date' => $attributes['start_date'],
                'end_date' => $attributes['end_date'],
            ]);

            if ($addresses->isNotEmpty()) {
                $this->sites->attach($job, $addresses);
            }

            $job->recordInitialStatus();
            $job->recordActivity('created', "Job created from {$sources->count()} selected estimate(s)");

            $merged = Estimate::create([
                'job_id' => $job->id,
                'project_id' => $original->project_id,
                'number' => Estimate::nextNumber($user),
                'client' => $job->client,
                'project' => $job->name,
                'issued_on' => now()->toDateString(),
                'status' => Estimate::STATUS_FOR_A_LIVE_JOB,
                'kind' => Estimate::KIND_MERGED,
                // The same commercial terms as the original — a job priced
                // from several estimates has one markup and one tax rate, not
                // whichever each source happened to carry.
                'markup_pct' => $original->markup_pct,
                'tax_pct' => $original->tax_pct,
                'amount' => 0,
            ]);

            $this->cloneItems($sources, $merged);

            $merged->recalculateTotals();
            app(EstimateBuilder::class)->syncJobBudget($merged);

            // Each selected source has now actually been put to work in a job —
            // the same "a job existing is what accepting an estimate means"
            // rule `Estimate::statusFor()` applies everywhere else, just
            // applied here to the sources instead of the merged estimate born
            // from them. Their totals/lines/kind are untouched; only this.
            foreach ($sources as $source) {
                if ($source->status !== Estimate::STATUS_FOR_A_LIVE_JOB) {
                    $source->update(['status' => Estimate::STATUS_FOR_A_LIVE_JOB]);
                }
            }

            $merged->mergeSources()->attach(
                $sources->mapWithKeys(fn (Estimate $source) => [$source->id => ['created_at' => now()]])->all(),
            );

            $job->recordActivity(
                'estimate_created',
                "Estimate {$merged->number} created from ".$sources->count().' selected estimate(s)',
                ['estimate_id' => $merged->id, 'source_estimate_ids' => $sources->pluck('id')->all()],
            );

            EstimateGenerated::dispatch($merged);

            return $job;
        });
    }

    /**
     * Clones every line from the selected sources onto the merged estimate.
     *
     * A line identical to one already cloned (same category, description,
     * unit and rate — the same physical thing counted on two different
     * takeoffs, most often a manually-added line repeated on an addendum) has
     * its quantity added rather than being written twice; anything else is
     * its own line, `source_estimate_item_id` pointing back to where it came
     * from.
     *
     * @param  Collection<int, Estimate>  $sources
     */
    private function cloneItems(Collection $sources, Estimate $merged): void
    {
        $position = 0;
        /** @var array<string, EstimateItem> $clonedByKey */
        $clonedByKey = [];

        foreach ($sources as $source) {
            foreach ($source->items as $item) {
                $key = implode('|', [
                    $item->category,
                    mb_strtolower(trim($item->description)),
                    mb_strtolower(trim($item->unit)),
                    (string) $item->unit_cost,
                ]);

                if (isset($clonedByKey[$key])) {
                    $existing = $clonedByKey[$key];
                    $existing->update(['quantity' => (float) $existing->quantity + (float) $item->quantity]);

                    continue;
                }

                $clonedByKey[$key] = $merged->items()->create([
                    'final_symbol_id' => $item->final_symbol_id,
                    'source_estimate_item_id' => $item->id,
                    'category' => $item->category,
                    'description' => $item->description,
                    'unit' => $item->unit,
                    'quantity' => $item->quantity,
                    'unit_cost' => $item->unit_cost,
                    'source' => $item->source,
                    'position' => $position++,
                ]);
            }
        }
    }
}
