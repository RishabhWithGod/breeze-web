<?php

namespace App\Http\Controllers;

use App\Events\EstimateGenerated;
use App\Http\Requests\StoreJobFromEstimatesRequest;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Job;
use App\Services\Clients\JobSites;
use App\Services\Takeoff\EstimateBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Raises one new job from a standalone estimate plus whichever addenda were
 * selected on the Addendum screen — the multi-estimate half of the Addendum
 * feature (`AddendumController` is the read-only, single-estimate half).
 *
 * Every selected estimate is read, never mutated: their lines are cloned onto
 * a brand-new `kind = merged` estimate, so an estimate already used by an
 * earlier job (or that will be used by a later one) is never taken from it.
 * From there this reuses the ordinary job pipeline unchanged — `jobs.tasks.setup`
 * builds tasks from `$job->estimates()` exactly as it does for any takeoff-built
 * job, and the existing assignment flow follows it untouched.
 */
class JobFromEstimatesController extends Controller
{
    public function __construct(private readonly JobSites $sites) {}

    public function store(StoreJobFromEstimatesRequest $request): RedirectResponse
    {
        $userId = $request->user()->id;
        $data = $request->validated();

        // Loaded and re-checked here rather than trusted from the request:
        // ownership was validated per-id, but "all on the same project" and
        // "none of them is itself a merge" need the rows in hand.
        $sources = Estimate::query()
            ->whereIn('id', $data['estimate_ids'])
            ->where('user_id', $userId)
            ->where('kind', '!=', Estimate::KIND_MERGED)
            ->with('items')
            ->get();

        abort_unless(
            $sources->count() === count($data['estimate_ids']),
            422,
            'One of the selected estimates could not be used — it may already be a merged estimate.',
        );

        $projectIds = $sources->pluck('project_id')->unique();
        abort_unless($projectIds->count() === 1, 422, 'Selected estimates must all be on the same project.');

        $addresses = $this->sites->resolve((int) $sources->first()->client_id, $data['address_ids']);

        $job = DB::transaction(function () use ($sources, $data, $addresses, $request) {
            $original = $sources->firstWhere('kind', Estimate::KIND_STANDALONE) ?? $sources->first();

            $job = Job::create([
                'user_id' => $request->user()->id,
                'project_id' => $original->project_id,
                'client_id' => $original->client_id,
                'client' => $original->client,
                'name' => $data['name'],
                'team_id' => $data['team_id'],
                'status' => 'planning',
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
            ]);

            $this->sites->attach($job, $addresses);
            $job->recordInitialStatus();
            $job->recordActivity('created', "Job created from {$sources->count()} selected estimate(s)");

            $merged = Estimate::create([
                'job_id' => $job->id,
                'project_id' => $original->project_id,
                'number' => Estimate::nextNumber($request->user()),
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

        return redirect()
            ->route('jobs.tasks.setup', $job)
            ->with('success', "\"{$job->name}\" was created from the selected estimates.");
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
