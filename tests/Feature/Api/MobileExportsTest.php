<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Foreman;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileExportsTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $u): array
    {
        return ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken];
    }

    public function test_a_manager_downloads_the_invoice_list_as_csv(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $client = Client::create(['user_id' => $manager->id, 'name' => 'Apex']);
        Invoice::create([
            'user_id' => $manager->id, 'client_id' => $client->id, 'client' => 'Apex',
            'invoice_number' => Invoice::nextNumber($manager), 'invoice_date' => now()->toDateString(),
            'status' => Invoice::STATUS_DRAFT, 'created_by' => $manager->id,
        ]);

        $response = $this->get('/api/v1/invoices/export', $this->headers($manager))->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Invoice #', $csv);
        $this->assertStringContainsString('Apex', $csv);
    }

    public function test_time_exports_are_csv_or_xlsx_and_reports_are_manager_only(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $tech = User::factory()->create(['role' => 'Journeyman']);
        $job = Job::create([
            'user_id' => $manager->id,
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside', 'client' => 'R', 'status' => 'in-progress',
        ]);
        TimeEntry::create([
            'job_id' => $job->id, 'user_id' => $tech->id, 'date' => now()->toDateString(),
            'hours' => 3, 'status' => TimeEntry::STATUS_APPROVED, 'source' => TimeEntry::SOURCE_MANUAL,
        ]);

        $this->get('/api/v1/time-tracking/reports/export/csv', $this->headers($manager))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/time-tracking/entries/export/csv', $this->headers($manager))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/time-tracking/entries/export/pdf', $this->headers($manager))->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/time-tracking/reports/export/csv', $this->headers($tech))->assertForbidden();
    }
}
