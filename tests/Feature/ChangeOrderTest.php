<?php

namespace Tests\Feature;

use App\Models\ChangeOrder;
use App\Models\CompanyProfile;
use App\Models\Foreman;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Job;
use App\Models\User;
use App\Notifications\ChangeOrderStatusChanged;
use App\Services\ChangeOrders\ChangeOrderAccess;
use App\Services\ChangeOrders\ChangeOrderBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Change orders: added work documented after a job begins — made by hand, submitted, decided by a
 * manager, and billed with the job once approved.
 */
class ChangeOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $foremanUser;

    private Foreman $foreman;

    private Job $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'Project Manager']);
        $company = CompanyProfile::create([
            'user_id' => $this->manager->id, 'name' => 'Volt & Co', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        $this->manager->forceFill(['company_id' => $company->id])->save();
        $this->manager = $this->manager->fresh();
        $this->foremanUser = User::factory()->create(['role' => 'Foreman', 'company_id' => $company->id]);
        $this->foreman = (new Foreman(['name' => 'Dana Wu', 'initials' => 'DW', 'role' => 'foreman']))->forceFill(['user_id' => $this->foremanUser->id, 'company_id' => $this->manager->company_id]);
        $this->foreman->save();
        $this->job = $this->makeJob(['foreman_id' => $this->foreman->id]);
    }

    private function makeJob(array $attributes = []): Job
    {
        return Job::create([
            'user_id' => $this->manager->id,
            'name' => 'Riverside Medical Center',
            'client' => 'Riverside',
            'status' => 'in-progress',
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'job_id' => $this->job->id,
            'description' => 'Add dedicated circuit for MRI suite',
            'reason' => 'The owner added an MRI room.',
            'source' => 'office',
            'markup_pct' => 20,
            'lines' => [
                ['kind' => 'material', 'description' => '#6 copper wire', 'quantity' => 100, 'unit' => 'LF', 'unit_cost' => 12.5],
                ['kind' => 'material', 'description' => '60A breaker', 'quantity' => 1, 'unit' => 'EA', 'unit_cost' => 100],
                ['kind' => 'labor', 'description' => 'Journeyman install', 'quantity' => 16, 'unit' => 'HR', 'unit_cost' => 50],
            ],
            ...$overrides,
        ];
    }

    private function make(?User $as = null, array $overrides = []): ChangeOrder
    {
        $this->actingAs($as ?? $this->manager)->post(route('change-orders.store'), $this->payload($overrides))->assertSessionHasNoErrors();

        return ChangeOrder::withoutGlobalScopes()->orderByDesc('id')->firstOrFail();
    }

    public function test_a_change_order_is_made_by_hand_with_its_totals_worked_out_on_the_server(): void
    {
        $co = $this->make();

        $this->assertSame('CO-001', $co->label());
        $this->assertSame('draft', $co->status);
        $this->assertSame('office', $co->source);
        $this->assertEquals(16, $co->labor_hours);
        $this->assertEquals(800, $co->labor_cost);
        $this->assertEquals(1350, $co->material_cost);
        $this->assertEquals(2150, $co->cost_total);
        // Cost plus its markup.
        $this->assertEquals(2580, $co->sell_total);
        $this->assertSame(3, $co->lines()->count());
        $this->assertSame(['created'], $co->events->pluck('type')->all());

        $this->assertSame('CO-002', $this->make()->label());
    }

    public function test_it_is_checked_before_it_is_kept(): void
    {
        $this->actingAs($this->manager)->post(route('change-orders.store'), $this->payload(['description' => '', 'lines' => []]))
            ->assertSessionHasErrors(['description', 'lines']);
        $this->actingAs($this->manager)->post(route('change-orders.store'), $this->payload(['lines' => [['kind' => 'material', 'description' => '', 'quantity' => 0, 'unit_cost' => -1]]]))
            ->assertSessionHasErrors(['lines.0.description', 'lines.0.quantity', 'lines.0.unit_cost']);
        $this->assertSame(0, ChangeOrder::count());

        // A completed job takes no more added work, and nor does another company's job.
        $done = $this->makeJob(['status' => 'completed']);
        $this->actingAs($this->manager)->post(route('change-orders.store'), $this->payload(['job_id' => $done->id]))->assertForbidden();
        $other = User::factory()->create(['role' => 'Project Manager']);
        $theirs = $this->makeJob(['user_id' => $other->id]);
        $this->actingAs($this->manager)->post(route('change-orders.store'), $this->payload(['job_id' => $theirs->id]))->assertNotFound();
    }

    public function test_submitting_then_approving_or_rejecting_is_a_managers_call_and_is_kept_in_the_history(): void
    {
        Notification::fake();
        $co = $this->make($this->foremanUser);
        $this->assertSame('field', $co->source);

        $this->actingAs($this->foremanUser)->post(route('change-orders.submit', $co))->assertSessionHasNoErrors();
        $this->assertSame('submitted', $co->fresh()->status);
        Notification::assertSentTo($this->manager, ChangeOrderStatusChanged::class);

        // A submitted one is locked; a foreman cannot decide their own.
        $this->actingAs($this->foremanUser)->put(route('change-orders.update', $co), $this->payload())->assertForbidden();
        $this->actingAs($this->foremanUser)->post(route('change-orders.approve', $co))->assertForbidden();

        $this->actingAs($this->manager)->post(route('change-orders.reject', $co), ['note' => ''])->assertSessionHasErrors('note');
        $this->actingAs($this->manager)->post(route('change-orders.reject', $co), ['note' => 'Price the wire again.'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $co->fresh()->status);
        Notification::assertSentTo($this->foremanUser, ChangeOrderStatusChanged::class);

        // Changing it takes it back to draft, and it can go round again.
        $this->actingAs($this->foremanUser)->put(route('change-orders.update', $co), $this->payload(['markup_pct' => 10]))->assertSessionHasNoErrors();
        $this->assertSame('draft', $co->fresh()->status);
        $this->assertEquals(2365, $co->fresh()->sell_total);
        $this->actingAs($this->foremanUser)->post(route('change-orders.submit', $co));
        $this->actingAs($this->manager)->post(route('change-orders.approve', $co), ['note' => 'Go ahead.'])->assertRedirect(route('change-orders.index'));

        $co->refresh();
        $this->assertSame('approved', $co->status);
        $this->assertSame($this->manager->id, $co->decided_by);
        $this->assertSame(['created', 'submitted', 'rejected', 'updated', 'submitted', 'approved'], $co->events()->reorder('id')->pluck('type')->all());

        // Approved is final.
        $this->actingAs($this->manager)->put(route('change-orders.update', $co), $this->payload())->assertForbidden();
        $this->actingAs($this->manager)->delete(route('change-orders.destroy', $co))->assertForbidden();
    }

    public function test_nothing_can_be_submitted_without_lines_and_a_submission_can_be_withdrawn(): void
    {
        $co = $this->make();
        $co->lines()->delete();
        $this->actingAs($this->manager)->post(route('change-orders.submit', $co))->assertSessionHasErrors('lines');

        $co = $this->make();
        $this->actingAs($this->manager)->post(route('change-orders.submit', $co));
        $this->actingAs($this->manager)->post(route('change-orders.withdraw', $co))->assertSessionHasNoErrors();
        $this->assertSame('draft', $co->fresh()->status);
    }

    public function test_an_approved_amount_goes_on_the_jobs_billing_once(): void
    {
        $invoice = Invoice::create(['user_id' => $this->manager->id, 'invoice_number' => 'INV-1', 'job_id' => $this->job->id, 'client' => 'Riverside', 'invoice_date' => now(), 'status' => 'draft', 'tax_pct' => 0, 'created_by' => $this->manager->id]);
        $sent = Invoice::create(['user_id' => $this->manager->id, 'invoice_number' => 'INV-2', 'job_id' => $this->job->id, 'client' => 'Riverside', 'invoice_date' => now(), 'status' => 'sent', 'tax_pct' => 0, 'created_by' => $this->manager->id]);

        $co = $this->make();
        $this->actingAs($this->manager)->post(route('change-orders.submit', $co));
        $this->actingAs($this->manager)->post(route('change-orders.approve', $co));

        $line = InvoiceItem::where('change_order_id', $co->id)->sole();
        $this->assertSame($invoice->id, $line->invoice_id);
        $this->assertSame('change_order', $line->source);
        $this->assertEquals(2580, $line->total);
        $this->assertEquals(2580, $invoice->fresh()->total);
        // An invoice already sent is never altered.
        $this->assertSame(0, $sent->items()->count());

        // Again changes nothing, and the show page says where it is billed.
        app(ChangeOrderBilling::class)->attach($co->fresh());
        $this->assertSame(1, InvoiceItem::where('change_order_id', $co->id)->count());
        $this->actingAs($this->manager)->get(route('change-orders.show', $co))->assertInertia(fn (Assert $page) => $page
            ->where('changeOrder.billing.state', 'billed')->where('changeOrder.billing.invoices.0.number', 'INV-1'));

        // A job's next invoice picks up what was already approved.
        $new = Invoice::create(['user_id' => $this->manager->id, 'invoice_number' => 'INV-3', 'job_id' => $this->job->id, 'client' => 'Riverside', 'invoice_date' => now(), 'status' => 'draft', 'tax_pct' => 0, 'created_by' => $this->manager->id]);
        app(ChangeOrderBilling::class)->attachApprovedTo($new);
        $this->assertEquals(2580, $new->fresh()->total);
    }

    public function test_evidence_is_attached_downloaded_and_removed(): void
    {
        Storage::fake('local');
        $co = $this->make();

        $this->actingAs($this->manager)->post(route('change-orders.attach', $co), ['attachments' => [UploadedFile::fake()->image('panel.jpg'), UploadedFile::fake()->create('quote.pdf', 20, 'application/pdf')]])->assertSessionHasNoErrors();
        $this->assertSame(2, $co->attachments()->count());

        $file = $co->attachments()->first();
        Storage::disk('local')->assertExists($file->path);
        $this->actingAs($this->manager)->get(route('change-orders.download', [$co, $file]))->assertOk();

        $this->actingAs($this->manager)->post(route('change-orders.attach', $co), ['attachments' => [UploadedFile::fake()->create('virus.exe', 5)]])->assertSessionHasErrors('attachments.0');

        $this->actingAs($this->manager)->delete(route('change-orders.detach', [$co, $file]))->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($file->path);
        $this->assertSame(1, $co->attachments()->count());

        // Evidence can also come with the form.
        $this->actingAs($this->manager)->post(route('change-orders.store'), [...$this->payload(), 'attachments' => [UploadedFile::fake()->image('site.png')]])->assertSessionHasNoErrors();
        $this->assertSame(1, ChangeOrder::orderByDesc('id')->first()->attachments()->count());
    }

    public function test_a_foreman_raises_them_only_for_assigned_jobs_and_sees_only_their_own(): void
    {
        $other = $this->makeJob(['name' => 'Not mine', 'foreman_id' => Foreman::create(['name' => 'Someone', 'initials' => 'SO', 'role' => 'foreman'])->id]);
        $this->actingAs($this->foremanUser)->post(route('change-orders.store'), $this->payload(['job_id' => $other->id]))->assertForbidden();

        $mine = $this->make($this->foremanUser);
        $theirs = $this->make($this->manager);

        $this->actingAs($this->foremanUser)->get(route('change-orders.index'))->assertInertia(fn (Assert $page) => $page
            ->has('orders', 1)->where('orders.0.id', $mine->id));
        $this->actingAs($this->foremanUser)->get(route('change-orders.show', $theirs))->assertNotFound();
        $this->actingAs($this->foremanUser)->get(route('change-orders.create'))->assertInertia(fn (Assert $page) => $page->has('jobs', 1)->where('isManager', false));

        // A manager sees both, and any change order can be changed by a manager.
        $this->actingAs($this->manager)->get(route('change-orders.index'))->assertInertia(fn (Assert $page) => $page->has('orders', 2));
        $this->actingAs($this->manager)->put(route('change-orders.update', $mine), $this->payload())->assertSessionHasNoErrors();
    }

    public function test_a_company_only_sees_its_own_and_the_crew_cannot_open_it(): void
    {
        $co = $this->make();

        $rival = User::factory()->create(['role' => 'Project Manager']);
        $this->actingAs($rival)->get(route('change-orders.show', $co))->assertNotFound();
        $this->actingAs($rival)->post(route('change-orders.approve', $co))->assertNotFound();
        $this->actingAs($rival)->get(route('change-orders.index'))->assertInertia(fn (Assert $page) => $page->has('orders', 0));

        foreach (['Journeyman', 'Apprentice', 'Electrician'] as $role) {
            $crew = User::factory()->create(['role' => $role]);
            $this->actingAs($crew)->get(route('change-orders.index'))->assertForbidden();
        }
    }

    public function test_the_list_searches_filters_and_a_draft_can_be_deleted(): void
    {
        $a = $this->make(null, ['description' => 'Cable tray reroute']);
        $b = $this->make(null, ['description' => 'Panel upgrade', 'source' => 'field']);
        $this->actingAs($this->manager)->post(route('change-orders.submit', $b));

        $ids = fn (array $query) => $this->actingAs($this->manager)->get(route('change-orders.index', $query))->viewData('page')['props']['orders'];

        $this->assertCount(1, $ids(['search' => 'cable']));
        $this->assertCount(1, $ids(['search' => 'CO-002']));
        $this->assertCount(1, $ids(['search' => '1']));
        $this->assertCount(1, $ids(['status' => 'submitted']));
        $this->assertCount(1, $ids(['source' => 'field']));
        $this->assertCount(2, $ids(['job' => $this->job->id]));
        $this->assertCount(0, $ids(['search' => 'Riverside x']));
        $this->actingAs($this->manager)->get(route('change-orders.index', ['status' => 'bogus']))->assertSessionHasErrors('status');

        $this->actingAs($this->manager)->delete(route('change-orders.destroy', $b))->assertForbidden();
        $this->actingAs($this->manager)->delete(route('change-orders.destroy', $a))->assertRedirect(route('change-orders.index'));
        $this->assertNull(ChangeOrder::find($a->id));
    }

    public function test_a_jobs_page_lists_its_change_orders_and_offers_to_raise_one(): void
    {
        $mine = $this->make();
        $this->make(null, ['job_id' => $this->makeJob(['name' => 'Elsewhere'])->id]);

        $this->actingAs($this->manager)->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page
            ->where('changeOrders.canRaise', true)->has('changeOrders.items', 1)->where('changeOrders.items.0.id', $mine->id));

        // The form opens with this job already chosen.
        $this->actingAs($this->manager)->get(route('change-orders.create', ['job' => $this->job->id]))->assertInertia(fn (Assert $page) => $page->where('preselectedJob', $this->job->id));

        // A completed job can no longer be added to, and the crew have no such tab.
        $done = $this->makeJob(['status' => 'completed']);
        $this->actingAs($this->manager)->get(route('jobs.show', $done))->assertInertia(fn (Assert $page) => $page->where('changeOrders.canRaise', false));
        $crew = User::factory()->create(['role' => 'Journeyman']);
        $this->actingAs($this->manager)->get(route('jobs.show', $this->job));
        $this->assertFalse(app(ChangeOrderAccess::class)->canUse($crew));
    }
}
