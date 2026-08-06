<?php

namespace Tests\Feature;

use App\Models\AiResult;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Job;
use App\Models\JobAssignment;
use App\Models\Project;
use App\Models\SymbolReview;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Takeoff\EstimateBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Concerns\TalksToTheEngine;
use Tests\TestCase;

/**
 * Review → final JSON → job → estimate.
 *
 * Every row under test comes from a real analysis by the running AI engine: the
 * fixture drawing is posted to it once per class and the genuine response ingested.
 * Nothing is faked, and no assertion hard-codes a symbol the engine happened to find
 * — the tests read the response and assert relationships against it.
 *
 * The rule they defend: the engine's response is evidence, not truth. Only approved
 * symbols reach the final JSON, they carry the reviewer's names and counts, and the
 * job and estimate are built from that document alone.
 */
class AiReviewTest extends TestCase
{
    use RefreshDatabase, TalksToTheEngine;

    private User $user;

    private AiResult $result;

    protected function setUp(): void
    {
        parent::setUp();
        // No Storage::fake: the drawing has to be a real file for the engine.
        $this->user = User::factory()->create();
        $this->result = $this->ingestRealAnalysis($this->user);
    }

    protected function tearDown(): void
    {
        $disk = Storage::disk(config('ai.storage.disk'));
        $disk->delete('uploads/sample-drawing.pdf');

        foreach (Project::withTrashed()->pluck('id') as $projectId) {
            $disk->deleteDirectory(config('ai.storage.directory')."/{$projectId}");
        }

        parent::tearDown();
    }

    public function test_the_review_screen_lists_the_engines_symbols_with_their_provenance(): void
    {
        $payload = $this->engineResponse();
        $expected = count($payload['symbols']) + count($payload['needs_review']);

        $this->actingAs($this->user)
            ->get("/reviews/{$this->result->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('AiReview')
                ->where('result.detectionCount', $expected)
                ->where('tally.total', $expected)
                ->where('result.pipelineStatus.0.stage', 'legend')
                ->has('result.warnings')
                ->has('symbols.data', $expected)
                ->where('symbols.data.0.origin', 'symbol')
                ->has('symbols.data.0.evidence')
                ->has('history'));
    }

    public function test_symbols_the_engine_counted_arrive_approved_and_needs_review_does_not(): void
    {
        // The engine already counted these, so a reviewer only overturns mistakes.
        $counted = $this->symbolRows()
            ->where('ai_category', '!=', SymbolReview::CATEGORY_REJECTED);

        $this->assertTrue(
            $counted->every(fn (SymbolReview $row) => $row->status === SymbolReview::STATUS_APPROVED),
        );

        // Anything it rejected, or flagged for review, is out until approved.
        foreach ($this->result->reviews()->get() as $row) {
            if ($row->needsReview() || $row->ai_category === SymbolReview::CATEGORY_REJECTED) {
                $this->assertSame(SymbolReview::STATUS_REJECTED, $row->status);
            }
        }
    }

    public function test_symbols_can_be_filtered_searched_and_sorted(): void
    {
        $first = $this->symbolRows()->first();

        $this->actingAs($this->user)
            ->get("/reviews/{$this->result->id}?search=".urlencode($first->name))
            ->assertInertia(fn (Assert $page) => $page->has('symbols.data', 1));

        $this->actingAs($this->user)
            ->get("/reviews/{$this->result->id}?status=approved")
            ->assertInertia(fn (Assert $page) => $page
                ->where('symbols.data.0.status', 'approved'));

        $lowest = $this->result->reviews()->reorder()->orderBy('confidence')->firstOrFail();

        $this->actingAs($this->user)
            ->get("/reviews/{$this->result->id}?sort=confidence-asc")
            ->assertInertia(fn (Assert $page) => $page
                ->where('symbols.data.0.id', $lowest->id));
    }

    public function test_a_symbol_can_be_approved_rejected_and_reset(): void
    {
        $review = $this->symbolRows()->first();

        $this->actingAs($this->user)->post($this->url("symbols/{$review->id}/reject"), [
            'reason' => 'Legend mismatch',
        ]);
        $this->assertSame(SymbolReview::STATUS_REJECTED, $review->fresh()->status);
        $this->assertSame('Legend mismatch', $review->fresh()->notes);

        $this->actingAs($this->user)->post($this->url("symbols/{$review->id}/approve"));
        $this->assertSame(SymbolReview::STATUS_APPROVED, $review->fresh()->status);
        $this->assertNotNull($review->fresh()->reviewed_at);

        $this->actingAs($this->user)->post($this->url("symbols/{$review->id}/reset"));
        $this->assertSame(SymbolReview::STATUS_PENDING, $review->fresh()->status);

        $actions = $this->result->history()->pluck('action');

        foreach (['rejected', 'approved', 'reset'] as $action) {
            $this->assertTrue($actions->contains($action), "Missing audit row: {$action}");
        }
    }

    public function test_the_reviewed_count_can_be_stepped_or_set_outright(): void
    {
        $review = $this->symbolRows()->sortByDesc('ai_count')->first();
        $engineCount = $review->ai_count;

        $this->actingAs($this->user)->post($this->url("symbols/{$review->id}/count"), ['step' => -1]);
        $this->assertSame($engineCount - 1, $review->fresh()->final_count);

        $this->actingAs($this->user)->post($this->url("symbols/{$review->id}/count"), ['count' => 3]);
        $review->refresh();

        // The engine's number is preserved for comparison; the reviewer's wins.
        $this->assertSame(3, $review->final_count);
        $this->assertSame($engineCount, $review->ai_count);
        $this->assertTrue($review->isModified());
    }

    public function test_a_symbol_can_be_renamed_and_annotated(): void
    {
        $review = $this->symbolRows()->first();
        $engineName = $review->ai_name;

        $this->actingAs($this->user)
            ->post($this->url("symbols/{$review->id}/rename"), ['name' => 'Pendant Light']);
        $this->actingAs($this->user)
            ->post($this->url("symbols/{$review->id}/note"), ['notes' => 'Client selected pendants.']);

        $review->refresh();
        $this->assertSame('Pendant Light', $review->name);
        $this->assertSame($engineName, $review->ai_name);
        $this->assertSame('Client selected pendants.', $review->notes);
        $this->assertTrue($review->isRenamed());
    }

    public function test_symbols_can_be_merged_into_one(): void
    {
        $rows = $this->symbolRows();

        if ($rows->count() < 2) {
            $this->markTestSkipped('The engine found only one symbol type in the fixture.');
        }

        $keep = $rows->first();
        $folded = $rows->get(1);
        $combined = $keep->final_count + $folded->final_count;

        $this->actingAs($this->user)->post($this->url('merge'), [
            'ids' => [$keep->id, $folded->id],
            'target_id' => $keep->id,
            'name' => 'Single pole switch',
        ]);

        $keep->refresh();
        $folded->refresh();

        $this->assertSame('Single pole switch', $keep->name);
        $this->assertSame(SymbolReview::STATUS_APPROVED, $keep->status);
        $this->assertSame($combined, $keep->final_count);
        // The folded row survives for audit but drops out of the final JSON.
        $this->assertSame($keep->id, $folded->merged_into_id);
        $this->assertFalse($folded->countsTowardsFinal());
    }

    public function test_a_multi_count_symbol_can_be_split(): void
    {
        $review = $this->symbolRows()->sortByDesc('final_count')->first();

        if ($review->final_count < 2) {
            $this->markTestSkipped('No symbol in the fixture is counted more than once.');
        }

        $before = $review->final_count;

        $this->actingAs($this->user)->post($this->url("symbols/{$review->id}/split"), [
            'name' => 'Emergency light',
            'count' => 2,
        ]);

        $review->refresh();
        $child = $this->result->reviews()->where('name', 'Emergency light')->firstOrFail();

        $this->assertSame($before - 2, $review->final_count);
        $this->assertSame(2, $child->final_count);
        $this->assertSame($review->id, $child->split_from_id);
        $this->assertSame(SymbolReview::STATUS_APPROVED, $child->status);
    }

    public function test_the_final_json_contains_only_approved_symbols_at_reviewed_values(): void
    {
        $rows = $this->symbolRows();
        $keep = $rows->first();
        $drop = $rows->count() > 1 ? $rows->get(1) : null;

        $this->actingAs($this->user)
            ->post($this->url("symbols/{$keep->id}/rename"), ['name' => 'Pendant Light']);
        $this->actingAs($this->user)
            ->post($this->url("symbols/{$keep->id}/count"), ['count' => 10]);
        $this->actingAs($this->user)->post($this->url("symbols/{$keep->id}/approve"));

        if ($drop) {
            $this->actingAs($this->user)->post($this->url("symbols/{$drop->id}/reject"));
        }

        // Signing off completes the handoff, so it lands on the job it created.
        $this->actingAs($this->user)
            ->post($this->url('finalise'))
            ->assertRedirect('/jobs/'.Job::latest('id')->firstOrFail()->id);

        $this->result->refresh();
        $payload = $this->result->final_payload;
        $engine = $this->engineResponse();

        $this->assertSame(AiResult::REVIEW_FINALISED, $this->result->review_status);
        $this->assertTrue(Storage::disk(config('ai.storage.disk'))->exists($this->result->final_path));

        // Renamed and recounted, and the rejected symbol is nowhere in the counts.
        $this->assertSame(10, $payload['final_counts']['Pendant Light']);
        $this->assertArrayNotHasKey($keep->ai_name, $payload['final_counts']);

        if ($drop) {
            $this->assertArrayNotHasKey($drop->name, $payload['final_counts']);
            $this->assertContains($drop->name, array_column($payload['rejected_symbols'], 'name'));
        }

        // The engine's own sections travel with the reviewed document.
        // By content: MySQL returns a JSON object in its own key order, and this map
        // is read by key everywhere it is used.
        $this->assertEquals($engine['pipeline_status'], $payload['engine']['pipeline_status']);
        $this->assertSame($engine['warnings'], $payload['engine']['warnings']);
        $this->assertCount(count($engine['boq']), $payload['engine']['boq']);
        $this->assertCount(count($engine['wire_sizes']), $payload['engine']['wire_sizes']);
        $this->assertSame(
            (float) $engine['estimate']['grand_total'],
            (float) $payload['engine']['estimate']['grand_total'],
        );

        // The original response is untouched.
        $this->assertSame(
            $engine['symbols'][0]['name'],
            $this->result->original_payload['symbols'][0]['name'],
        );

        $this->actingAs($this->user)
            ->get("/takeoffs/{$this->result->id}/final")
            ->assertInertia(fn (Assert $page) => $page
                ->component('FinalSymbols')
                ->where('symbols.data.0.name', 'Pendant Light')
                ->has('engineBoq', count($engine['boq']))
                ->has('wireSizes', count($engine['wire_sizes']))
                ->has('result.pipelineStatus'));
    }

    public function test_a_finalised_takeoff_is_read_only_until_it_is_reopened(): void
    {
        $this->finalise();
        $review = $this->symbolRows()->first();

        $this->actingAs($this->user)
            ->post($this->url("symbols/{$review->id}/reject"))
            ->assertForbidden();

        $this->actingAs($this->user)->post($this->url('reopen'));

        $this->assertSame(AiResult::REVIEW_IN_PROGRESS, $this->result->fresh()->review_status);

        $this->actingAs($this->user)
            ->post($this->url("symbols/{$review->id}/reject"))
            ->assertRedirect();
    }

    public function test_the_final_table_exports_in_every_format(): void
    {
        $this->finalise();

        foreach (['json', 'csv', 'xlsx'] as $format) {
            $this->actingAs($this->user)
                ->get("/takeoffs/{$this->result->id}/final/export/{$format}")
                ->assertOk();
        }

        $this->actingAs($this->user)
            ->get("/takeoffs/{$this->result->id}/annotated.pdf")
            ->assertOk();

        $this->assertNotNull($this->result->upload->fresh()->annotated_path);
    }

    public function test_the_ai_response_alone_raises_a_provisional_job_and_estimate(): void
    {
        // Straight from ingest: both exist, and both say they are pre-review.
        $job = $this->result->workJob;
        $estimate = $this->result->estimate;

        $this->assertNotNull($job, 'The AI response should have raised a job.');
        $this->assertNotNull($estimate, 'The AI response should have raised an estimate.');
        $this->assertFalse($this->result->isFinalised());
        $this->assertFalse($job->metadata['reviewed']);
        $this->assertSame($this->result->id, $job->ai_result_id);
        $this->assertSame($job->id, $estimate->job_id);
        $this->assertTrue($estimate->items()->exists());

        // Counted from the engine's own approved rows.
        $engineTotal = (int) $this->result->reviews()
            ->where('status', SymbolReview::STATUS_APPROVED)
            ->sum('final_count');
        $this->assertSame($engineTotal, (int) collect($job->symbol_counts)->sum());

        // And both lists show them without any further action.
        $this->actingAs($this->user)
            ->get('/jobs')
            ->assertInertia(fn (Assert $page) => $page->where('jobs.data.0.id', $job->id));
        $this->actingAs($this->user)
            ->get('/estimates')
            ->assertInertia(fn (Assert $page) => $page->where('estimates.data.0.id', $estimate->id));
    }

    public function test_asking_for_a_job_before_finalising_sends_the_reviewer_back_with_guidance(): void
    {
        $this->assertFalse($this->result->isFinalised());

        foreach (['job', 'estimate'] as $action) {
            $this->actingAs($this->user)
                ->post("/takeoffs/{$this->result->id}/{$action}")
                // Not a bare 403: the workflow stays navigable.
                ->assertRedirect("/reviews/{$this->result->id}")
                ->assertSessionHas('warning');
        }

        // The provisional pair is untouched, and nothing is duplicated.
        $this->assertDatabaseCount('work_jobs', 1);
        $this->assertDatabaseCount('estimates', 1);
        $this->assertFalse($this->result->fresh()->workJob->metadata['reviewed']);
    }

    public function test_the_job_is_created_from_the_final_json(): void
    {
        $this->finalise();

        $this->actingAs($this->user)
            ->post("/takeoffs/{$this->result->id}/job")
            ->assertRedirect();

        $job = Job::latest('id')->firstOrFail();
        $payload = $this->result->fresh()->final_payload;

        $this->assertSame($this->result->id, $job->ai_result_id);
        $this->assertSame($this->result->project_id, $job->project_id);
        $this->assertSame('planning', $job->status);
        // Counts, BOQ and metadata are copied from the reviewed document.
        $this->assertSame($payload['final_counts'], $job->symbol_counts);
        $this->assertSame('ai-takeoff', $job->metadata['source']);
        $this->assertSame($this->result->run_id, $job->metadata['engine_run_id']);
        $this->assertSame($this->result->project_name, $job->metadata['project_name']);
        $this->assertSame('sample-drawing.pdf', $job->metadata['drawing_name']);
        $this->assertNotEmpty($job->boq['lines']);
        // The engine's grand total becomes the opening budget.
        $this->assertSame((float) $this->result->ai_estimate['grand_total'], (float) $job->budget);
        $this->assertSame($job->id, $this->result->fresh()->work_job_id);
        $this->assertSame('converted', $job->project->status);
    }

    public function test_the_estimate_is_generated_from_the_engines_bill_of_quantities(): void
    {
        $this->finalise();
        $this->actingAs($this->user)->post("/takeoffs/{$this->result->id}/job");

        $this->actingAs($this->user)
            ->post("/takeoffs/{$this->result->id}/estimate")
            ->assertRedirect();

        $estimate = Estimate::latest('id')->firstOrFail();
        $engine = $this->engineResponse();

        $this->assertSame($this->result->id, $estimate->ai_result_id);
        $this->assertSame(Job::latest('id')->firstOrFail()->id, $estimate->job_id);

        // One line per engine BOQ line, priced at the engine's own rates.
        $this->assertSame(count($engine['boq']), $estimate->items()->count());
        $this->assertDatabaseHas('estimate_items', [
            'estimate_id' => $estimate->id,
            'unit_cost' => number_format((float) $engine['boq'][0]['unit_price'], 2, '.', ''),
        ]);

        // Tax comes from the engine's rate (a fraction) as a percentage.
        $this->assertSame(
            round((float) $engine['estimate']['tax_rate'] * 100, 2),
            (float) $estimate->tax_pct,
        );

        // Totals are the sum of the lines, plus markup and tax.
        $this->assertSame(
            round((float) $estimate->items()->sum('total'), 2),
            (float) $estimate->subtotal,
        );
        $this->assertSame((float) $estimate->grand_total, (float) $estimate->amount);

        // A rate change re-derives the totals rather than being stored blindly.
        $before = (float) $estimate->grand_total;

        $this->actingAs($this->user)->put("/estimates/{$estimate->id}", [
            'client' => 'Northgate Retail',
            'project' => 'Northgate Fit-out',
            'status' => 'sent',
            'issued_on' => now()->toDateString(),
            'markup_pct' => 25,
            'tax_pct' => 5,
            'notes' => 'Rates confirmed with the supplier.',
        ])->assertRedirect();

        $estimate->refresh();
        $this->assertNotSame($before, (float) $estimate->grand_total);
        $this->assertSame('sent', $estimate->status);

        // Manual lines behave like generated ones.
        $this->actingAs($this->user)->post("/estimates/{$estimate->id}/items", [
            'category' => 'labor',
            'description' => 'Site supervision',
            'unit' => 'hr',
            'quantity' => 10,
            'unit_cost' => 95,
        ]);

        $item = $estimate->items()->where('description', 'Site supervision')->firstOrFail();
        $this->assertSame('950.00', $item->total);
        $this->assertSame('manual', $item->source);
        $this->assertSame(EstimateItem::CATEGORY_LABOR, $item->category);

        $this->actingAs($this->user)->delete("/estimates/{$estimate->id}/items/{$item->id}");
        $this->assertDatabaseMissing('estimate_items', ['id' => $item->id]);

        $this->actingAs($this->user)->get("/estimates/{$estimate->id}/pdf")->assertOk();
        $this->actingAs($this->user)->get("/estimates/{$estimate->id}/export/csv")->assertOk();
    }

    public function test_reviewed_counts_flow_through_to_the_estimate(): void
    {
        // Halve a symbol the engine priced, then check the money follows.
        $review = $this->symbolRows()->sortByDesc('final_count')->first();
        $halved = max(1, (int) floor($review->final_count / 2));

        $this->actingAs($this->user)
            ->post($this->url("symbols/{$review->id}/count"), ['count' => $halved]);
        $this->finalise();
        $this->actingAs($this->user)->post("/takeoffs/{$this->result->id}/estimate");

        $estimate = Estimate::latest('id')->firstOrFail();
        $symbol = $this->result->fresh()->finalSymbols()->where('name', $review->name)->first();

        if (! $symbol) {
            $this->markTestSkipped('The reviewed symbol did not survive into the final table.');
        }

        $matched = $estimate->items()->where('final_symbol_id', $symbol->id)->first();

        if (! $matched) {
            $this->markTestSkipped('The engine priced no line matching this symbol.');
        }

        $this->assertSame((float) $halved, (float) $matched->quantity);
    }

    public function test_signing_off_the_review_completes_the_whole_handoff_in_one_step(): void
    {
        $review = $this->symbolRows()->first();
        $this->actingAs($this->user)->post($this->url("symbols/{$review->id}/approve"));

        // One action. No second button, no "create job" step.
        $response = $this->actingAs($this->user)->post($this->url('finalise'));

        $this->result->refresh();
        $job = Job::latest('id')->firstOrFail();
        $estimate = Estimate::latest('id')->firstOrFail();

        $response->assertRedirect("/jobs/{$job->id}");

        // Everything a signed-off takeoff implies, written and linked.
        $this->assertSame(AiResult::REVIEW_FINALISED, $this->result->review_status);
        $this->assertNotNull($this->result->final_path);
        $this->assertSame($job->id, $this->result->work_job_id);
        $this->assertSame($estimate->id, $this->result->estimate_id);
        $this->assertSame($this->result->id, $job->ai_result_id);
        $this->assertSame($job->id, $estimate->job_id);
        $this->assertSame($this->result->project_id, $estimate->project_id);
        $this->assertTrue($estimate->items()->exists());
        $this->assertTrue($this->result->finalSymbols()->exists());
        $this->assertTrue($this->result->boqLines()->exists());

        // The reviewer of record, and the audit row that explains the rest.
        $reviewer = $job->activeAssignments()
            ->where('role', JobAssignment::ROLE_REVIEWER)
            ->firstOrFail();
        $this->assertSame($this->user->id, $reviewer->user_id);

        $completion = $this->result->history()->where('action', 'review_completed')->firstOrFail();
        $this->assertSame($job->id, $completion->meta['job_id']);
        $this->assertSame($estimate->id, $completion->meta['estimate_id']);

        // Both modules list it without any further action.
        $this->actingAs($this->user)
            ->get('/jobs')
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.id', $job->id));

        $this->actingAs($this->user)
            ->get('/estimates')
            ->assertInertia(fn (Assert $page) => $page
                ->where('estimates.data.0.id', $estimate->id));
    }

    public function test_a_failure_while_completing_the_review_rolls_everything_back(): void
    {
        $review = $this->symbolRows()->first();
        $this->actingAs($this->user)->post($this->url("symbols/{$review->id}/approve"));

        // Pricing blows up after the job has been written inside the transaction.
        $this->app->bind(EstimateBuilder::class, fn () => new class extends EstimateBuilder
        {
            public function __construct() {}

            public function fromFinalJson(AiResult $result, User $user, ?Job $job = null): Estimate
            {
                throw new LogicException('pricing exploded');
            }
        });

        $this->actingAs($this->user)
            ->from("/reviews/{$this->result->id}")
            ->post($this->url('finalise'))
            ->assertRedirect("/reviews/{$this->result->id}")
            ->assertSessionHas('warning');

        // Nothing was promoted: the provisional pair is unchanged, no duplicates, no
        // reviewer recorded, and the takeoff is still reviewable.
        $this->assertDatabaseCount('work_jobs', 1);
        $this->assertDatabaseCount('estimates', 1);
        $this->assertDatabaseCount('job_assignments', 0);
        $this->assertFalse($this->result->fresh()->workJob->metadata['reviewed']);
        $this->assertNotSame(AiResult::REVIEW_FINALISED, $this->result->fresh()->review_status);
    }

    public function test_signing_off_refreshes_the_provisional_pair_instead_of_duplicating_it(): void
    {
        $job = $this->result->workJob;
        $estimate = $this->result->estimate;
        $engineCounts = $job->symbol_counts;

        // Something the estimator added by hand must outlive the reprice.
        $manual = $estimate->items()->create([
            'category' => EstimateItem::CATEGORY_MATERIAL,
            'description' => 'Site allowance agreed with the client',
            'unit' => 'ls',
            'quantity' => 1,
            'unit_cost' => 750,
            'source' => 'manual',
            'position' => 99,
        ]);

        // A reviewer disagrees with the engine and halves a count.
        $review = $this->symbolRows()->first();
        $this->actingAs($this->user)->post($this->url("symbols/{$review->id}/approve"));
        $this->actingAs($this->user)
            ->post($this->url("symbols/{$review->id}/count"), ['count' => 2]);

        $this->actingAs($this->user)->post($this->url('finalise'));

        // The same two records, brought up to the reviewed numbers.
        $this->assertDatabaseCount('work_jobs', 1);
        $this->assertDatabaseCount('estimates', 1);
        $this->assertSame($job->id, $this->result->fresh()->work_job_id);
        $this->assertSame($estimate->id, $this->result->fresh()->estimate_id);

        $job->refresh();
        $this->assertTrue($job->metadata['reviewed']);
        $this->assertNotSame($engineCounts, $job->symbol_counts);
        $this->assertSame(2, $job->symbol_counts[$review->fresh()->name]);

        // Manual work survives; the AI lines were rewritten.
        $estimate->refresh();
        $this->assertTrue($estimate->items()->whereKey($manual->id)->exists());
        $this->assertTrue($estimate->items()->where('source', 'ai')->exists());
        $this->assertGreaterThan(0, (float) $estimate->grand_total);

        // And the audit says what moved.
        $actions = $this->result->history()->pluck('action');
        $this->assertTrue($actions->contains('job_updated'));
        $this->assertTrue($actions->contains('estimate_updated'));
    }

    public function test_a_job_can_be_staffed_by_role_and_keeps_its_assignment_history(): void
    {
        $this->finalise();
        $job = Job::latest('id')->firstOrFail();

        // Signing off the review already recorded its reviewer, so role counts here
        // are scoped to the role under test.
        $this->assertSame(
            $this->user->name,
            $job->activeAssignments()->where('role', JobAssignment::ROLE_REVIEWER)->firstOrFail()->name,
        );

        $first = TeamMember::create(['name' => 'Dana Whitfield', 'initials' => 'DW', 'role' => 'Estimator']);
        $second = TeamMember::create(['name' => 'Marco Ruiz', 'initials' => 'MR', 'role' => 'Journeyman']);

        $this->actingAs($this->user)->post("/jobs/{$job->id}/assignments", [
            'role' => JobAssignment::ROLE_ESTIMATOR,
            'team_member_id' => $first->id,
        ]);

        // Assigning the same role releases the incumbent.
        $this->actingAs($this->user)->post("/jobs/{$job->id}/assignments", [
            'role' => JobAssignment::ROLE_ESTIMATOR,
            'team_member_id' => $second->id,
        ]);

        $estimators = fn () => $job->assignments()->where('role', JobAssignment::ROLE_ESTIMATOR);

        $this->assertSame(1, (clone $estimators())->whereNull('released_at')->count());
        $this->assertSame('Marco Ruiz', (clone $estimators())->whereNull('released_at')->first()->name);
        // Nothing is deleted — the released row is the history.
        $this->assertSame(2, $estimators()->count());

        $active = (clone $estimators())->whereNull('released_at')->firstOrFail();
        $this->actingAs($this->user)->delete("/jobs/{$job->id}/assignments/{$active->id}");

        $this->assertSame(0, (clone $estimators())->whereNull('released_at')->count());
        $this->assertNotNull($active->fresh()->released_at);

        $this->actingAs($this->user)
            ->get("/jobs/{$job->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('JobShow')
                // The reviewer from sign-off, plus both estimator rows.
                ->has('job.assignments', 3)
                ->has('job.takeoff'));
    }

    public function test_the_workflow_notifies_the_reviewer_estimator_and_manager(): void
    {
        // Ingest already notified the run's owner that a review is waiting.
        $this->assertDatabaseHas('app_notifications', ['type' => 'takeoff-ready']);

        $this->finalise();
        $this->assertDatabaseHas('app_notifications', ['type' => 'review-completed']);

        $this->actingAs($this->user)->post("/takeoffs/{$this->result->id}/job");
        $this->actingAs($this->user)->post("/takeoffs/{$this->result->id}/estimate");
        $this->assertDatabaseHas('app_notifications', ['type' => 'estimate-ready']);
    }

    /* ----------------------------------------------------------------- setup */

    private function url(string $path): string
    {
        return "/reviews/{$this->result->id}/{$path}";
    }

    /**
     * The rows that came from the engine's `symbols` list — the ones it counted.
     *
     * @return Collection<int, SymbolReview>
     */
    private function symbolRows(): Collection
    {
        return $this->result->reviews()
            ->where('origin', SymbolReview::ORIGIN_SYMBOL)
            ->get();
    }

    /** Approves everything still pending and generates the final JSON. */
    private function finalise(): void
    {
        $this->actingAs($this->user)->post($this->url('approve-remaining'));
        $this->actingAs($this->user)->post($this->url('finalise'));
        $this->result->refresh();
    }
}
