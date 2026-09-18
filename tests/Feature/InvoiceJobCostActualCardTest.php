<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\JobCostEntry;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The invoice screen's "Job Cost Details" panel — the Estimated/Actual pair
 * of cards, `InvoiceDetailController::show`'s `jobCostSummary` prop.
 *
 * The Actual card already reads from real, recorded data
 * (`JobCostSummary::for()` — approved `time_entries` for labor hours/cost,
 * `job_cost_entries` for materials/equipment/other), never from the
 * estimate and never hardcoded; this locks that in for the one screen with
 * no test coverage of it at all, and is the concrete "multiple Journeymen"
 * scenario asked for.
 */
class InvoiceJobCostActualCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_actual_labor_hours_sum_every_journeymans_recorded_hours_for_the_job(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $journeymanA = User::factory()->create(['role' => 'Journeyman']);
        $journeymanB = User::factory()->create(['role' => 'Journeyman']);
        $journeymanC = User::factory()->create(['role' => 'Journeyman']);

        $job = Job::create([
            'user_id' => $manager->id,
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside Office Renovation',
            'client' => 'Riverside Properties LLC',
            'status' => 'in-progress',
        ]);

        $this->makeTimeEntry($job, $journeymanA, 8, 8 * 45);
        $this->makeTimeEntry($job, $journeymanB, 6, 6 * 45);
        $this->makeTimeEntry($job, $journeymanC, 5, 5 * 45);

        // A pending entry for the same job must not be counted yet.
        $this->makeTimeEntry($job, $journeymanA, 3, 3 * 45, TimeEntry::STATUS_SUBMITTED);

        // A real, recorded material cost — the same "not from the estimate,
        // not hardcoded" discipline applies to every other actual category.
        JobCostEntry::create([
            'job_id' => $job->id,
            'category' => JobCostEntry::CATEGORY_MATERIAL,
            'description' => 'Panel and breakers',
            'quantity' => 1,
            'unit_cost' => 640,
            'amount' => 640,
            'incurred_on' => now()->toDateString(),
        ]);

        $invoice = Invoice::create([
            'user_id' => $manager->id,
            'invoice_number' => Invoice::nextNumber($manager),
            'job_id' => $job->id,
            'client' => $job->client,
            'invoice_date' => now()->toDateString(),
            'subtotal' => 0,
            'tax_total' => 0,
            'total' => 0,
            'status' => Invoice::STATUS_DRAFT,
        ]);

        $this->actingAs($manager)
            ->get("/invoices/{$invoice->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('InvoiceShow')
                // 8 + 6 + 5 = 19 — the submitted entry is excluded.
                ->where('jobCostSummary.actualLaborHours', 19)
                ->where('jobCostSummary.actualLaborCost', (8 + 6 + 5) * 45)
                ->where('jobCostSummary.actualMaterialCost', 640)
                // The Estimated card is untouched by any of this — no tasks
                // or estimate were raised for this job.
                ->where('jobCostSummary.estimatedLaborHours', 0)
                ->where('jobCostSummary.estimatedLaborCost', 0));
    }

    private function makeTimeEntry(Job $job, User $user, float $hours, float $laborCost, string $status = TimeEntry::STATUS_APPROVED): TimeEntry
    {
        $entry = TimeEntry::create([
            'job_id' => $job->id,
            'user_id' => $user->id,
            'date' => now()->toDateString(),
            'hours' => $hours,
            'regular_hours' => $hours,
            'overtime_hours' => 0,
            'billable' => true,
            'source' => 'manual',
            'status' => $status,
            'labor_cost' => $laborCost,
        ]);
        $entry->recordInitialStatus();

        return $entry;
    }
}
