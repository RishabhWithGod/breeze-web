<?php

namespace Tests\Feature\StaticTakeoff;

use App\Models\StaticTakeoffDataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StaticTakeoffImportCommandTest extends TestCase
{
    use RefreshDatabase;

    private function writeJson(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data));
    }

    public function test_it_imports_a_valid_dataset(): void
    {
        Storage::fake('local');

        $pdf = tempnam(sys_get_temp_dir(), 'static').'.pdf';
        file_put_contents($pdf, '%PDF-1.4 fixture');

        $payload = tempnam(sys_get_temp_dir(), 'payload').'.json';
        $this->writeJson($payload, [
            'project_name' => 'Fixture Panel',
            'symbols' => [['name' => 'Duplex Receptacle', 'count' => 5]],
        ]);

        $this->artisan('takeoff:static-import', ['pdf' => $pdf, 'payload' => $payload, '--name' => 'Fixture'])
            ->assertSuccessful();

        $hash = hash_file('sha256', $pdf);

        $this->assertDatabaseHas('static_takeoff_datasets', [
            'file_hash' => $hash,
            'name' => 'Fixture',
            'is_active' => true,
        ]);

        $dataset = StaticTakeoffDataset::where('file_hash', $hash)->firstOrFail();
        $this->assertSame('Fixture Panel', $dataset->takeoff_payload['project_name']);

        @unlink($pdf);
        @unlink($payload);
    }

    public function test_it_merges_a_separate_estimate_file(): void
    {
        Storage::fake('local');

        $pdf = tempnam(sys_get_temp_dir(), 'static').'.pdf';
        file_put_contents($pdf, '%PDF-1.4 fixture 2');

        $payload = tempnam(sys_get_temp_dir(), 'payload').'.json';
        $this->writeJson($payload, ['symbols' => [['name' => 'Panel', 'count' => 1]]]);

        $estimate = tempnam(sys_get_temp_dir(), 'estimate').'.json';
        $this->writeJson($estimate, [
            'estimate' => ['subtotal' => 100, 'grand_total' => 108.25],
            'boq' => [['item' => 'Panel', 'quantity' => 1, 'unit_price' => 100]],
        ]);

        $this->artisan('takeoff:static-import', ['pdf' => $pdf, 'payload' => $payload, '--estimate' => $estimate])
            ->assertSuccessful();

        $dataset = StaticTakeoffDataset::where('file_hash', hash_file('sha256', $pdf))->firstOrFail();
        $this->assertSame(108.25, $dataset->takeoff_payload['estimate']['grand_total']);
        $this->assertSame('Panel', $dataset->takeoff_payload['boq'][0]['item']);
        $this->assertNotNull($dataset->metadata['imported_estimate_source']);

        @unlink($pdf);
        @unlink($payload);
        @unlink($estimate);
    }

    public function test_it_rejects_a_payload_with_no_symbols(): void
    {
        $pdf = tempnam(sys_get_temp_dir(), 'static').'.pdf';
        file_put_contents($pdf, '%PDF-1.4 fixture 3');

        $payload = tempnam(sys_get_temp_dir(), 'payload').'.json';
        $this->writeJson($payload, ['project_name' => 'Empty']);

        $this->artisan('takeoff:static-import', ['pdf' => $pdf, 'payload' => $payload])
            ->assertFailed();

        $this->assertDatabaseCount('static_takeoff_datasets', 0);

        @unlink($pdf);
        @unlink($payload);
    }

    public function test_it_rejects_a_missing_pdf(): void
    {
        $payload = tempnam(sys_get_temp_dir(), 'payload').'.json';
        $this->writeJson($payload, ['symbols' => [['name' => 'X', 'count' => 1]]]);

        $this->artisan('takeoff:static-import', ['pdf' => '/tmp/does-not-exist.pdf', 'payload' => $payload])
            ->assertFailed();

        @unlink($payload);
    }

    public function test_reimporting_the_same_pdf_updates_the_existing_row(): void
    {
        Storage::fake('local');

        $pdf = tempnam(sys_get_temp_dir(), 'static').'.pdf';
        file_put_contents($pdf, '%PDF-1.4 fixture 4');

        $payloadV1 = tempnam(sys_get_temp_dir(), 'payload').'.json';
        $this->writeJson($payloadV1, ['symbols' => [['name' => 'A', 'count' => 1]]]);

        $this->artisan('takeoff:static-import', ['pdf' => $pdf, 'payload' => $payloadV1])->assertSuccessful();

        $payloadV2 = tempnam(sys_get_temp_dir(), 'payload').'.json';
        $this->writeJson($payloadV2, ['symbols' => [['name' => 'B', 'count' => 2]]]);

        $this->artisan('takeoff:static-import', ['pdf' => $pdf, 'payload' => $payloadV2])->assertSuccessful();

        $this->assertDatabaseCount('static_takeoff_datasets', 1);
        $dataset = StaticTakeoffDataset::where('file_hash', hash_file('sha256', $pdf))->firstOrFail();
        $this->assertSame('B', $dataset->takeoff_payload['symbols'][0]['name']);

        @unlink($pdf);
        @unlink($payloadV1);
        @unlink($payloadV2);
    }
}
