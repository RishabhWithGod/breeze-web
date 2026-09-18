<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Foreman;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\JobCostEntry;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The invoice screen's "Job Cost Details" panel — the Estimated/Actual pair
 * of cards plus the "Journeyman Labor Hours" card, all from
 * `InvoiceDetailController::show`'s `jobCostSummary`/`journeymanHours` props.
 *
 * The Actual card reads from real, recorded data (`JobCostSummary::for()` —
 * `time_entries` for labor hours/cost, `job_cost_entries` for materials/
 * equipment/other), never from the estimate and never hardcoded — and, since
 * hours no longer wait on the Time Tracking approval workflow, every entry
 * counts the moment it exists (a `locked` entry excluded — its correction is
 * what counts instead of it).
 */
class InvoiceJobCostActualCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_actual_labor_hours_sum_every_journeymans_recorded_hours_regardless_of_approval(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $journeymanA = User::factory()->create(['role' => 'Journeyman']);
        $journeymanB = User::factory()->create(['role' => 'Journeyman']);
        $journeymanC = User::factory()->create(['role' => 'Journeyman']);

        // A $45/hr rate on the client, so actual labor cost is deterministic
        // rather than whatever `config('ai.estimating.labor_rate')` happens
        // to be — the same override `EstimateBuilder::laborRateFor()` reads.
        $client = Client::create(['user_id' => $manager->id, 'name' => 'Riverside Properties LLC', 'labor_rate' => 45]);
        $project = Project::create([
            'user_id' => $manager->id,
            'client_id' => $client->id,
            'name' => 'Riverside Office Renovation',
            'client' => $client->name,
            'status' => 'in-progress',
        ]);

        $job = Job::create([
            'user_id' => $manager->id,
            'project_id' => $project->id,
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside Office Renovation',
            'client' => 'Riverside Properties LLC',
            'status' => 'in-progress',
        ]);

        $this->makeTimeEntry($job, $journeymanA, 8, 8 * 45, TimeEntry::STATUS_APPROVED);
        $this->makeTimeEntry($job, $journeymanB, 6, 6 * 45, TimeEntry::STATUS_DRAFT);
        $this->makeTimeEntry($job, $journeymanC, 5, 5 * 45, TimeEntry::STATUS_SUBMITTED);
        // A second session for A, still not approved — counts anyway now.
        $this->makeTimeEntry($job, $journeymanA, 3, 3 * 45, TimeEntry::STATUS_SUBMITTED);
        // Superseded by a correction — must never be counted, approval or not.
        $this->makeTimeEntry($job, $journeymanA, 100, 100 * 45, TimeEntry::STATUS_LOCKED);

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
                // 8 + 6 + 5 + 3 = 22 — every status counts, the locked 100 does not.
                ->where('jobCostSummary.actualLaborHours', 22)
                ->where('jobCostSummary.actualLaborCost', (8 + 6 + 5 + 3) * 45)
                ->where('jobCostSummary.actualMaterialCost', 640)
                // The Estimated card is untouched by any of this — no tasks
                // or estimate were raised for this job.
                ->where('jobCostSummary.estimatedLaborHours', 0)
                ->where('jobCostSummary.estimatedLaborCost', 0)
                // The Journeyman Labor Hours card sums to the same total.
                ->has('journeymanHours', 3)
                ->where('can.manageJobCosts', true));

        // A manager can set A's total directly — no submit/approve needed —
        // and that saved figure is what counts from here on.
        $this->actingAs($manager)
            ->put("/jobs/{$job->id}/journeyman-hours/{$journeymanA->id}", ['hours' => 20])
            ->assertSessionHasNoErrors();

        $this->actingAs($manager)
            ->get("/invoices/{$invoice->id}")
            ->assertInertia(fn (Assert $page) => $page
                // B (6) + C (5) + A's edited 20, not A's raw 11.
                ->where('jobCostSummary.actualLaborHours', 31)
                ->where('journeymanHours.0.hours', 20)
                ->where('journeymanHours.0.isOverridden', true));
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
