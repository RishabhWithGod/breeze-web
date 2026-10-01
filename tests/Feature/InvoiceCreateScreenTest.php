<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Create Invoice screen builds the whole invoice: its lines (an estimate's,
 * edited, or typed), its tax, and whether it is kept as a draft or issued.
 */
class InvoiceCreateScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Client $client;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'Project Manager']);
        $this->client = Client::create(['user_id' => $this->manager->id, 'name' => 'Harborview']);
        $this->project = Project::create([
            'user_id' => $this->manager->id,
            'client_id' => $this->client->id,
            'name' => 'Data Hall',
            'client' => 'Harborview',
            'status' => 'draft',
        ]);
    }

    private function estimate(): Estimate
    {
        $estimate = Estimate::create([
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'number' => Estimate::nextNumber($this->manager),
            'client' => 'Harborview',
            'project' => 'Data Hall',
            'issued_on' => now()->toDateString(),
            'status' => 'approved',
            'kind' => Estimate::KIND_STANDALONE,
            'amount' => 0,
        ]);

        foreach ([['Panel install', 'labor', 500], ['Conduit run', 'material', 200]] as [$description, $category, $amount]) {
            $estimate->items()->create([
                'category' => $category, 'description' => $description, 'unit' => 'ea',
                'quantity' => 1, 'unit_cost' => $amount, 'total' => $amount, 'source' => 'manual',
            ]);
        }
        $estimate->recalculateTotals();

        return $estimate;
    }

    /** A valid request. Every invoice bills an estimate, so one is made unless a test names its own. */
    private function payload(array $overrides = []): array
    {
        return [
            'client_id' => $this->client->id,
            'invoice_date' => '2026-09-30',
            'tax_pct' => 10,
            'estimate_id' => $overrides['estimate_id'] ?? $this->estimate()->id,
            ...$overrides,
        ];
    }

    public function test_the_screen_carries_each_estimates_lines_so_they_can_be_shown_and_adjusted(): void
    {
        $estimate = $this->estimate();

        $this->actingAs($this->manager)
            ->get('/invoices/create')
            ->assertInertia(fn ($page) => $page
                ->component('InvoiceCreate')
                ->where('estimates.0.id', $estimate->id)
                ->has('estimates.0.items', 2)
                ->where('estimates.0.items.0.description', 'Panel install')
                ->where('estimates.0.items.0.source_category', 'labor')
                ->where('estimates.0.items.0.quantity', 1)
                ->where('estimates.0.items.0.unit_price', 500));
    }

    public function test_the_lines_sent_become_the_invoice_and_the_estimate_is_not_copied_over_them(): void
    {
        $estimate = $this->estimate();

        $this->actingAs($this->manager)
            ->post('/invoices', $this->payload([
                'estimate_id' => $estimate->id,
                'items' => [
                    // One of the estimate's lines, changed, and one typed.
                    ['description' => 'Panel install', 'source_category' => 'labor', 'quantity' => 2, 'unit_price' => 500],
                    ['description' => 'Permit fee', 'source_category' => 'other', 'quantity' => 1, 'unit_price' => 75.5],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $invoice = Invoice::sole();

        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->status);
        $this->assertSame(['Panel install', 'Permit fee'], $invoice->items()->orderBy('position')->pluck('description')->all());
        // 2 × 500 + 75.50 = 1075.50; 10% tax = 107.55; total 1183.05 — worked out on the server.
        $this->assertSame('1075.50', $invoice->subtotal);
        $this->assertSame('107.55', $invoice->tax_total);
        $this->assertSame('1183.05', $invoice->total);
    }

    public function test_an_estimate_with_no_lines_sent_still_copies_itself_in(): void
    {
        $estimate = $this->estimate();

        $this->actingAs($this->manager)
            ->post('/invoices', $this->payload(['estimate_id' => $estimate->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Invoice::sole()->items()->count());
    }

    public function test_issuing_sends_the_invoice_straight_away(): void
    {
        $this->actingAs($this->manager)
            ->post('/invoices', $this->payload([
                'issue' => true,
                'items' => [['description' => 'Site prep', 'source_category' => 'labor', 'quantity' => 1, 'unit_price' => 100]],
            ]))
            ->assertSessionHasNoErrors();

        $invoice = Invoice::sole();

        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);
        $this->assertNotNull($invoice->sent_at);
    }

    public function test_an_estimate_is_required(): void
    {
        $payload = $this->payload();
        unset($payload['estimate_id']);

        $this->actingAs($this->manager)
            ->post('/invoices', $payload)
            ->assertSessionHasErrors(['estimate_id' => 'Pick the estimate this invoice bills']);

        $this->assertSame(0, Invoice::count());
    }

    public function test_an_invoice_with_no_lines_cannot_be_issued(): void
    {
        $this->actingAs($this->manager)
            ->post('/invoices', $this->payload(['issue' => true, 'items' => []]))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, Invoice::count());
    }

    public function test_a_line_must_have_a_description_and_a_real_category(): void
    {
        $this->actingAs($this->manager)
            ->post('/invoices', $this->payload([
                'items' => [['description' => '', 'source_category' => 'snacks', 'quantity' => 1, 'unit_price' => 5]],
            ]))
            ->assertSessionHasErrors(['items.0.description', 'items.0.source_category']);

        $this->assertSame(0, Invoice::count());
    }

    public function test_a_job_that_is_not_completed_still_cannot_be_invoiced(): void
    {
        $job = Job::create([
            'user_id' => $this->manager->id, 'name' => 'Open job', 'client' => 'Harborview', 'status' => 'in-progress',
        ]);

        $this->actingAs($this->manager)
            ->post('/invoices', $this->payload([
                'job_id' => $job->id,
                'items' => [['description' => 'x', 'source_category' => 'other', 'quantity' => 1, 'unit_price' => 1]],
            ]))
            ->assertSessionHasErrors('job_id');

        $this->assertSame(0, Invoice::count());
    }

    /* ------------------------------------------------ one project, one client */

    private function completedJob(Project $project, Client $client, string $name = 'Finished job'): Job
    {
        return Job::create([
            'project_id' => $project->id, 'user_id' => $this->manager->id, 'name' => $name,
            'client' => $client->name, 'client_id' => $client->id, 'status' => 'completed',
        ]);
    }

    public function test_the_screen_tells_each_job_and_estimate_which_project_it_belongs_to(): void
    {
        $job = $this->completedJob($this->project, $this->client);
        $estimate = $this->estimate();
        $estimate->update(['job_id' => $job->id]);

        $this->actingAs($this->manager)
            ->get('/invoices/create')
            ->assertInertia(fn ($page) => $page
                ->where('jobs.0.project_id', $this->project->id)
                ->where('jobs.0.client_id', $this->client->id)
                ->where('estimates.0.job_id', $job->id)
                ->where('estimates.0.project_id', $this->project->id));
    }

    public function test_a_project_fixes_the_client_so_another_one_is_refused(): void
    {
        $job = $this->completedJob($this->project, $this->client);
        $other = Client::create(['user_id' => $this->manager->id, 'name' => 'Someone Else']);

        $this->actingAs($this->manager)
            ->post('/invoices', $this->payload([
                'job_id' => $job->id,
                'client_id' => $other->id,
                'items' => [['description' => 'x', 'source_category' => 'other', 'quantity' => 1, 'unit_price' => 1]],
            ]))
            ->assertSessionHasErrors('client_id');

        $this->assertSame(0, Invoice::count());

        // Its own client is accepted.
        $this->actingAs($this->manager)
            ->post('/invoices', $this->payload([
                'job_id' => $job->id,
                'items' => [['description' => 'x', 'source_category' => 'other', 'quantity' => 1, 'unit_price' => 1]],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Invoice::count());
    }

    public function test_a_project_only_bills_its_own_estimate(): void
    {
        $job = $this->completedJob($this->project, $this->client);

        $otherProject = Project::create([
            'user_id' => $this->manager->id, 'client_id' => $this->client->id,
            'name' => 'Other site', 'client' => 'Harborview', 'status' => 'draft',
        ]);
        $otherJob = $this->completedJob($otherProject, $this->client, 'Other job');

        $theirs = $this->estimate();
        $theirs->update(['job_id' => $otherJob->id, 'project_id' => $otherProject->id]);

        $this->actingAs($this->manager)
            ->post('/invoices', $this->payload(['job_id' => $job->id, 'estimate_id' => $theirs->id]))
            ->assertSessionHasErrors('estimate_id');

        $this->assertSame(0, Invoice::count());

        $mine = $this->estimate();
        $mine->update(['job_id' => $job->id]);

        $this->actingAs($this->manager)
            ->post('/invoices', $this->payload(['job_id' => $job->id, 'estimate_id' => $mine->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Invoice::count());
    }

    /* ------------------------------------------- a job's estimates and addenda */

    private function addendumOf(Estimate $original, int $number = 1, string $status = 'draft'): Estimate
    {
        return Estimate::create([
            'project_id' => $original->project_id,
            'client_id' => $this->client->id,
            'number' => Estimate::nextNumber($this->manager),
            'client' => 'Harborview',
            'project' => 'Data Hall',
            'issued_on' => now()->toDateString(),
            'status' => $status,
            'kind' => Estimate::KIND_ADDENDUM,
            'parent_estimate_id' => $original->id,
            'addendum_number' => $number,
            'amount' => 0,
        ]);
    }

    public function test_the_screen_lists_every_estimate_and_addendum_not_yet_invoiced_with_where_each_belongs(): void
    {
        $job = $this->completedJob($this->project, $this->client);
        $original = $this->estimate();
        $original->update(['job_id' => $job->id]);
        $addendum = $this->addendumOf($original, 2, 'draft');

        $this->actingAs($this->manager)
            ->get('/invoices/create')
            ->assertInertia(function ($page) use ($original, $addendum, $job) {
                $rows = collect($page->toArray()['props']['estimates'])->keyBy('id');

                $this->assertSame($job->id, $rows[$original->id]['owner_job_id']);
                $this->assertSame('standalone', $rows[$original->id]['kind']);

                // A draft addendum is listed too — and it belongs to its original's job.
                $this->assertSame($job->id, $rows[$addendum->id]['owner_job_id']);
                $this->assertNull($rows[$addendum->id]['job_id']);
                $this->assertSame('addendum', $rows[$addendum->id]['kind']);
                $this->assertSame(2, $rows[$addendum->id]['addendum_number']);
                $this->assertSame($original->number, $rows[$addendum->id]['parent_number']);
                $this->assertSame('draft', $rows[$addendum->id]['status']);
            });
    }

    public function test_an_addendum_can_be_billed_on_its_originals_job_but_not_another(): void
    {
        $job = $this->completedJob($this->project, $this->client);
        $original = $this->estimate();
        $original->update(['job_id' => $job->id]);
        $addendum = $this->addendumOf($original);

        $otherProject = Project::create([
            'user_id' => $this->manager->id, 'client_id' => $this->client->id,
            'name' => 'Other site', 'client' => 'Harborview', 'status' => 'draft',
        ]);
        $otherJob = $this->completedJob($otherProject, $this->client, 'Other job');

        // On a different project's job: refused.
        $this->actingAs($this->manager)
            ->post('/invoices', $this->payload(['job_id' => $otherJob->id, 'estimate_id' => $addendum->id]))
            ->assertSessionHasErrors('estimate_id');

        // On its original's job: accepted.
        $this->actingAs($this->manager)
            ->post('/invoices', $this->payload(['job_id' => $job->id, 'estimate_id' => $addendum->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($addendum->id, Invoice::sole()->estimate_id);
    }
}
