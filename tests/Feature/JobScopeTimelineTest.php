<?php

namespace Tests\Feature;

use App\Events\JobScopeChanged;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\Foreman;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Job;
use App\Models\JobCostEntry;
use App\Models\JobScopeChange;
use App\Models\Project;
use App\Models\User;
use App\Services\JobCosting\JobCostSummary;
use App\Services\JobScope\EstimateChangeRecorder;
use App\Services\JobScope\JobScopeHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Every change to a job's scope is remembered, broadcast, and carried to the
 * invoices — and an adjustment made on an invoice carries back into the job.
 */
class JobScopeTimelineTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Job $job;

    private Estimate $estimate;

    protected function setUp(): void
    {
        parent::setUp();
        EstimateChangeRecorder::reset();
        $this->manager = User::factory()->create(['role' => 'Project Manager']);

        $client = Client::create(['user_id' => $this->manager->id, 'name' => 'Riverside']);
        $project = Project::create(['user_id' => $this->manager->id, 'client_id' => $client->id, 'name' => 'P', 'client' => $client->name, 'status' => 'in-progress']);
        $this->job = Job::create([
            'user_id' => $this->manager->id, 'project_id' => $project->id,
            'foreman_id' => Foreman::create(['name' => 'Dana', 'initials' => 'D'])->id,
            'name' => 'Riverside', 'client' => $client->name, 'status' => 'in-progress',
        ]);
        $this->estimate = Estimate::create([
            'job_id' => $this->job->id, 'project_id' => $project->id, 'number' => 'EST-1', 'client' => 'Riverside', 'project' => 'P',
            'issued_on' => now()->toDateString(), 'status' => 'approved', 'amount' => 0,
        ]);
        $this->line('material', 'Panel', 1, 2000);
        $this->line('labor', 'Install', 10, 100);
        $this->estimate->recalculateTotals();
    }

    public function test_the_first_pricing_of_an_estimate_is_remembered(): void
    {
        $change = JobScopeChange::where('estimate_id', $this->estimate->id)->sole();

        $this->assertSame(1, $change->version);
        $this->assertSame('estimate', $change->kind);
        $this->assertSame(3000.0, (float) $change->totals_after['grand_total']);
        $this->assertSame(2, collect($change->item_changes)->where('action', 'added')->count());
    }

    public function test_an_edit_is_one_entry_with_what_changed_in_it(): void
    {
        $this->estimate->items()->where('description', 'Panel')->first()->update(['unit_cost' => 2500]);
        $this->line('material', 'Breaker', 4, 50);
        $this->estimate->recalculateTotals();

        $change = JobScopeChange::where('job_id', $this->job->id)->orderByDesc('version')->first();

        $this->assertSame(2, $change->version);
        $this->assertSame(700.0, (float) $change->amount);
        $this->assertSame('1 line added, 1 changed', $change->summary);
        $this->assertEqualsCanonicalizing(['added', 'changed'], collect($change->item_changes)->pluck('action')->all());
    }

    public function test_rewriting_the_same_lines_is_not_a_change(): void
    {
        $before = JobScopeChange::count();

        // What the Estimate Builder does on every save: the lines are deleted and written again as they were.
        $rows = $this->estimate->items()->get();
        EstimateChangeRecorder::touch($this->estimate->id);
        $this->estimate->items()->delete();
        foreach ($rows as $row) {
            $this->line($row->category, $row->description, (float) $row->quantity, (float) $row->unit_cost);
        }
        $this->estimate->recalculateTotals();

        $this->assertSame($before, JobScopeChange::count());
    }

    public function test_a_change_is_broadcast_without_any_dollar_figure(): void
    {
        Event::fake([JobScopeChanged::class]);

        $this->line('material', 'Breaker', 4, 50);
        $this->estimate->recalculateTotals();

        Event::assertDispatched(JobScopeChanged::class, function (JobScopeChanged $event) {
            $payload = $event->broadcastWith();

            return $payload['jobId'] === $this->job->id
                && $payload['version'] === 2
                && ! str_contains(json_encode($payload), '$')
                && ! array_key_exists('amount', $payload)
                && collect($event->broadcastOn())->map->name->contains("private-job.{$this->job->id}");
        });
    }

    public function test_an_estimate_change_updates_the_jobs_draft_invoice_but_never_a_sent_one(): void
    {
        $draft = $this->invoice('INV-1', 'draft');
        $sent = $this->invoice('INV-2', 'sent');
        $sentItems = $sent->items()->count();

        $this->line('material', 'Breaker', 4, 50);
        $this->estimate->recalculateTotals();

        $this->assertEquals(2200, $draft->items()->where('source_category', 'material')->sole()->total);
        $this->assertSame($sentItems, $sent->items()->count());
    }

    public function test_a_change_made_on_an_invoice_carries_back_into_the_job(): void
    {
        $this->actingAs($this->manager);
        $draft = $this->invoice('INV-1', 'draft');
        $other = $this->invoice('INV-2', 'draft');
        $materials = $draft->items()->where('source_category', 'material')->sole();

        $this->put(route('invoices.items.update', [$draft, $materials]), [
            'description' => 'Materials', 'source_category' => 'material', 'quantity' => 1, 'unit_price' => 2300,
        ])->assertRedirect();

        $entry = JobCostEntry::where('job_id', $this->job->id)->sole();
        $this->assertSame('invoice', $entry->source);
        $this->assertEquals(300, $entry->amount);

        // The job's actual agrees with the bill, the estimate itself is untouched.
        $row = app(JobCostSummary::class)->for($this->job->fresh());
        $this->assertSame(2300.0, $row['actualMaterialCost']);
        $this->assertSame(2000.0, $row['estimatedMaterialCost']);

        // The invoice it was changed on keeps exactly what the user typed; the job's other draft follows it.
        $this->assertEquals(2300, $draft->fresh()->items()->where('source_category', 'material')->sole()->total);
        $this->assertEquals(2300, $other->fresh()->items()->where('source_category', 'material')->sole()->total);

        $this->assertSame('invoice', JobScopeChange::where('job_id', $this->job->id)->orderByDesc('version')->first()->source);
    }

    public function test_a_line_added_on_an_invoice_is_actual_cost_and_taking_it_off_withdraws_it(): void
    {
        $this->actingAs($this->manager);
        $draft = $this->invoice('INV-1', 'draft');

        $this->post(route('invoices.items.store', $draft), [
            'description' => 'Permit', 'source_category' => 'other', 'quantity' => 1, 'unit_price' => 150,
        ])->assertRedirect();

        $this->assertSame(150.0, app(JobCostSummary::class)->for($this->job->fresh())['actualOtherCost']);

        $this->delete(route('invoices.items.destroy', [$draft, $draft->items()->where('description', 'Permit')->first()]))->assertRedirect();

        $this->assertSame(0.0, app(JobCostSummary::class)->for($this->job->fresh())['actualOtherCost']);
        $this->assertSame(0, JobCostEntry::where('job_id', $this->job->id)->count());
    }

    public function test_removing_a_billed_material_line_takes_it_out_of_the_jobs_actual(): void
    {
        $this->actingAs($this->manager);
        $draft = $this->invoice('INV-1', 'draft');

        $this->delete(route('invoices.items.destroy', [$draft, $draft->items()->where('source_category', 'material')->sole()]))->assertRedirect();

        $this->assertSame(0.0, app(JobCostSummary::class)->for($this->job->fresh())['actualMaterialCost']);
    }

    public function test_a_later_estimate_change_keeps_what_was_added_on_an_invoice(): void
    {
        $this->actingAs($this->manager);
        $draft = $this->invoice('INV-1', 'draft');
        $this->post(route('invoices.items.store', $draft), [
            'description' => 'Extra conduit', 'source_category' => 'material', 'quantity' => 1, 'unit_price' => 200,
        ]);

        $this->line('material', 'Breaker', 4, 50);
        $this->estimate->recalculateTotals();

        $this->assertEquals(2400, $draft->fresh()->items()->where('source_category', 'material')->sole()->total);
    }

    public function test_the_timeline_hides_dollar_figures_from_a_role_without_cost_access(): void
    {
        $this->line('material', 'Breaker', 4, 50);
        $this->estimate->recalculateTotals();

        $history = app(JobScopeHistory::class);
        $row = $history->for($this->job, canViewCosts: false)[0];

        $this->assertNull($row['amount']);
        $this->assertNull($row['totalAfter']);
        $this->assertArrayNotHasKey('total_after', $row['lines'][0]);

        $this->assertSame(200.0, $history->for($this->job, canViewCosts: true)[0]['amount']);
        $this->assertSame(2, $history->version($this->job));
    }

    public function test_where_a_change_came_from_is_remembered(): void
    {
        $this->line('material', 'Breaker', 4, 50);
        $this->estimate->recalculateTotals();

        $this->assertSame('web', JobScopeChange::where('job_id', $this->job->id)->orderByDesc('version')->first()->source);
    }

    public function test_saving_the_estimate_builder_remembers_only_what_actually_changed(): void
    {
        $worksheet = app(\App\Services\Estimating\EstimateWorksheet::class);
        $settings = ['tax_pct' => 0, 'markup_pct' => 0, 'labor_rate' => 100];
        $rows = [
            ['description' => 'Panel', 'material_qty' => 1, 'material_unit_price' => 2000, 'labor_hours' => 0, 'labor_rate' => 100, 'markup_pct' => 0],
            ['description' => 'Rough-in', 'material_qty' => 0, 'material_unit_price' => 0, 'labor_hours' => 10, 'labor_rate' => 100, 'markup_pct' => 0],
        ];

        $builder = Estimate::create(['job_id' => $this->job->id, 'number' => 'EST-B', 'client' => 'Riverside', 'project' => 'P', 'issued_on' => now()->toDateString(), 'status' => 'approved', 'amount' => 0, 'builder_managed' => true]);
        $worksheet->save($builder, $rows, $settings, $this->manager);
        $saved = $builder->builderLines()->get();
        $rows = $saved->map(fn ($l) => ['id' => $l->id, 'description' => $l->description, 'material_qty' => $l->material_qty, 'material_unit_price' => $l->material_unit_price, 'labor_hours' => $l->labor_hours, 'labor_rate' => $l->labor_rate, 'markup_pct' => 0])->all();

        $afterFirst = JobScopeChange::where('estimate_id', $builder->id)->count();
        $this->assertSame(1, $afterFirst);

        // Saved again exactly as it is: nothing happened.
        $worksheet->save($builder->fresh(), $rows, $settings, $this->manager);
        $this->assertSame($afterFirst, JobScopeChange::where('estimate_id', $builder->id)->count());

        // One price moved: one entry, naming that one line.
        $rows[0]['material_unit_price'] = 2400;
        $worksheet->save($builder->fresh(), $rows, $settings, $this->manager);

        $change = JobScopeChange::where('estimate_id', $builder->id)->orderByDesc('version')->first();
        $this->assertSame($afterFirst + 1, JobScopeChange::where('estimate_id', $builder->id)->count());
        // The job's version counts every change on the job, whichever estimate it was on.
        $this->assertSame(3, $change->version);
        $this->assertSame(['changed'], collect($change->item_changes)->pluck('action')->unique()->all());
        $this->assertSame(400.0, (float) $change->amount);
    }

    public function test_the_screens_carry_the_jobs_scope_version_and_history(): void
    {
        $this->line('material', 'Breaker', 4, 50);
        $this->estimate->recalculateTotals();
        $invoice = $this->invoice('INV-1', 'draft');

        $this->actingAs($this->manager);
        $this->get(route('job-costing.show', $this->job))->assertInertia(fn ($page) => $page
            ->where('scope.version', 2)->where('scope.history.0.summary', '1 line added')->where('scope.history.0.amount', 200));
        $this->get(route('invoices.show', $invoice))->assertInertia(fn ($page) => $page->where('scope.version', 2));
        $this->get(route('estimates.show', $this->estimate))->assertInertia(fn ($page) => $page->has('changes', 2));
    }

    public function test_the_phone_gets_the_version_and_only_what_is_newer(): void
    {
        $this->line('material', 'Breaker', 4, 50);
        $this->estimate->recalculateTotals();

        $h = ['Authorization' => 'Bearer '.$this->manager->createToken('t')->plainTextToken];
        $this->getJson("/api/v1/office/jobs/{$this->job->id}/scope", $h)
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonCount(2, 'data.history');

        $this->getJson("/api/v1/office/jobs/{$this->job->id}/scope?since=1", $h)
            ->assertOk()->assertJsonCount(1, 'data.history')->assertJsonPath('data.history.0.version', 2);

        $this->getJson("/api/v1/office/jobs/{$this->job->id}/scope?since=2", $h)->assertOk()->assertJsonCount(0, 'data.history');
    }

    public function test_the_manager_is_told_when_someone_else_changes_the_scope_away_from_the_office(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $pm = User::factory()->create(['role' => 'Project Manager']);
        \App\Models\JobAssignment::create(['job_id' => $this->job->id, 'user_id' => $pm->id, 'role' => \App\Models\JobAssignment::ROLE_PROJECT_MANAGER, 'name' => 'Pat PM', 'assigned_at' => now()]);

        // From the field, by someone else: the manager hears.
        $foreman = User::factory()->create(['role' => 'Foreman']);
        $this->actingAs($foreman);
        \App\Services\JobScope\ChangeSource::as('field', function () {
            $this->line('material', 'Extra box', 2, 10);
            $this->estimate->recalculateTotals();
        });
        \Illuminate\Support\Facades\Notification::assertSentTo($pm, \App\Notifications\JobScopeChangedNotice::class);

        // At the office desk, or by the manager themself: nobody needs telling.
        \Illuminate\Support\Facades\Notification::fake();
        $this->actingAs($pm);
        \App\Services\JobScope\ChangeSource::as('field', function () {
            $this->line('material', 'Another', 1, 10);
            $this->estimate->recalculateTotals();
        });
        \Illuminate\Support\Facades\Notification::assertNothingSent();

        $this->actingAs($foreman);
        $this->line('material', 'Web edit', 1, 10);
        $this->estimate->recalculateTotals();
        \Illuminate\Support\Facades\Notification::assertNothingSent();
    }

    public function test_a_bills_lines_keep_their_identity_when_the_estimate_changes(): void
    {
        $draft = $this->invoice('INV-1', 'draft');
        $materials = $draft->items()->where('source_category', 'material')->sole();

        $this->line('material', 'Breaker', 4, 50);
        $this->estimate->recalculateTotals();

        // Someone with that line open to edit it must still find it there.
        $this->assertSame($materials->id, $draft->items()->where('source_category', 'material')->sole()->id);
        $this->assertEquals(2200, $materials->fresh()->total);
    }

    private function line(string $category, string $description, float $qty, float $unitCost): void
    {
        $this->estimate->items()->create([
            'category' => $category, 'description' => $description, 'unit' => $category === 'labor' ? 'hr' : 'ea',
            'quantity' => $qty, 'unit_cost' => $unitCost, 'source' => 'manual',
            'position' => (int) $this->estimate->items()->max('position') + 1,
        ]);
    }

    private function invoice(string $number, string $status): Invoice
    {
        $invoice = Invoice::create([
            'user_id' => $this->manager->id, 'invoice_number' => $number, 'job_id' => $this->job->id, 'estimate_id' => $this->estimate->id,
            'client' => 'Riverside', 'invoice_date' => now()->toDateString(), 'status' => $status, 'tax_pct' => 0, 'created_by' => $this->manager->id,
        ]);

        if ($status === 'draft') {
            app(\App\Services\Billing\ActualCostInvoiceSync::class)->sync($this->job->fresh());
        } else {
            $invoice->items()->create(['description' => 'Billed', 'source_category' => 'other', 'source' => InvoiceItem::SOURCE_MANUAL, 'quantity' => 1, 'unit_price' => 100, 'total' => 100, 'position' => 0]);
        }

        return $invoice;
    }
}
