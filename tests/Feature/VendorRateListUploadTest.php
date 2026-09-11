<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\PriceBookImport;
use App\Models\PriceBookItem;
use App\Models\PriceBookLine;
use App\Models\User;
use App\Services\Takeoff\EstimateBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Uploading vendor rate lists on the project form gives that user their own
 * price book. Their estimates price off it before anything universal; a user
 * who never uploaded any still prices off the shared, universal book.
 *
 * Several workbooks in one submit pool into one book, the same way the
 * universal one is built from a folder of them: rates seen in more than one
 * workbook become one median rather than whatever the last file said.
 */
class VendorRateListUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploading_a_rate_list_on_project_create_prices_that_users_estimates(): void
    {
        $user = User::factory()->create();
        $client = $user->clients()->create(['name' => 'Harborview Electric']);

        // A universal item exists at a different rate, so the assertion below
        // can only pass if the upload — not the fallback — was actually used.
        $this->seedUniversalItem(60.0, 1.25);

        $workbook = $this->buildWorkbook([
            ['EM2, NEW BATTERY 2/HEAD EM FIXTURE', 'EA', 999.0, 48, 1.25],
        ], overheadPct: 0.10, profitPct: 0.12, taxPct: 0.075);

        $response = $this->actingAs($user)->post('/projects', [
            'client_id' => $client->id,
            'name' => 'Harborview Phase 2',
            'vendor_rate_list' => [$workbook],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionMissing('warning');

        $this->assertDatabaseHas('price_book_imports', [
            'user_id' => $user->id,
            'file_name' => 'vendor-rates.xlsx',
        ]);

        $item = PriceBookItem::where('user_id', $user->id)
            ->where('match_key', PriceBookLine::keyFor('EM2, NEW BATTERY 2/HEAD EM FIXTURE'))
            ->sole();

        $this->assertSame('999.0000', $item->unit_material_cost);

        $project = $user->projects()->sole();
        $aiJob = AiJob::create(['project_id' => $project->id, 'user_id' => $user->id, 'status' => 'completed']);
        $result = AiResult::create([
            'ai_job_id' => $aiJob->id,
            'project_id' => $project->id,
            'original_payload' => [],
            'final_payload' => ['final_counts' => []],
        ]);
        $result->finalSymbols()->create([
            'project_id' => $project->id,
            'name' => 'EM2',
            'count' => 2,
            'confidence' => 0.9,
        ]);

        $estimate = app(EstimateBuilder::class)->fromFinalJson($result, $user);
        $material = $estimate->items()->where('description', 'EM2, NEW BATTERY 2/HEAD EM FIXTURE')->sole();

        // Their own uploaded rate, not the universal book's 60.0.
        $this->assertSame('999.0000', $material->unit_cost);
        // Their own bid recap rates too, not config's defaults.
        $this->assertSame('7.50', $estimate->tax_pct);
    }

    public function test_a_user_who_never_uploaded_a_rate_list_prices_off_the_universal_book(): void
    {
        $uploader = User::factory()->create();
        $noUpload = User::factory()->create();
        $client = $noUpload->clients()->create(['name' => 'Northgate Electric']);

        $this->seedUniversalItem(60.0, 1.25);

        // Somebody else's own book must not leak into this user's estimate.
        $workbook = $this->buildWorkbook([
            ['EM2, NEW BATTERY 2/HEAD EM FIXTURE', 'EA', 999.0, 48, 1.25],
        ], overheadPct: 0.10, profitPct: 0.12, taxPct: 0.075);

        $this->actingAs($uploader)->post('/projects', [
            'client_id' => $uploader->clients()->create(['name' => 'Owner Co'])->id,
            'name' => 'Owner Project',
            'vendor_rate_list' => [$workbook],
        ]);

        $project = $noUpload->projects()->create([
            'name' => 'Northgate Fit-out',
            'client_id' => $client->id,
            'client' => $client->name,
            'status' => 'completed',
        ]);
        $aiJob = AiJob::create(['project_id' => $project->id, 'user_id' => $noUpload->id, 'status' => 'completed']);
        $result = AiResult::create([
            'ai_job_id' => $aiJob->id,
            'project_id' => $project->id,
            'original_payload' => [],
            'final_payload' => ['final_counts' => []],
        ]);
        $result->finalSymbols()->create([
            'project_id' => $project->id,
            'name' => 'EM2',
            'count' => 2,
            'confidence' => 0.9,
        ]);

        $estimate = app(EstimateBuilder::class)->fromFinalJson($result, $noUpload);
        $material = $estimate->items()->where('description', 'EM2, NEW BATTERY 2/HEAD EM FIXTURE')->sole();

        $this->assertSame('60.0000', $material->unit_cost);
    }

    public function test_several_workbooks_uploaded_together_pool_into_one_book(): void
    {
        $user = User::factory()->create();
        $client = $user->clients()->create(['name' => 'Harborview Electric']);

        $workbookA = $this->buildWorkbook([
            ['EM2, NEW BATTERY 2/HEAD EM FIXTURE', 'EA', 900.0, 48, 1.25],
        ], overheadPct: 0.10, profitPct: 0.12, taxPct: 0.075, fileName: 'workbook-a.xlsx');

        $workbookB = $this->buildWorkbook([
            ['EM2, NEW BATTERY 2/HEAD EM FIXTURE', 'EA', 1100.0, 48, 1.25],
            ['3/4" CONDUIT - EMT', 'FT', 0.75, 48, 0.06],
        ], overheadPct: 0.10, profitPct: 0.12, taxPct: 0.075, fileName: 'workbook-b.xlsx');

        $response = $this->actingAs($user)->post('/projects', [
            'client_id' => $client->id,
            'name' => 'Harborview Phase 2',
            'vendor_rate_list' => [$workbookA, $workbookB],
        ]);

        $response->assertSessionHas('success');
        $response->assertSessionMissing('warning');

        $this->assertSame(2, PriceBookImport::where('user_id', $user->id)->count());

        // Priced in both files: one item, its rate the median of the two.
        $em2 = PriceBookItem::where('user_id', $user->id)
            ->where('match_key', PriceBookLine::keyFor('EM2, NEW BATTERY 2/HEAD EM FIXTURE'))
            ->sole();
        $this->assertSame('1000.0000', $em2->unit_material_cost);
        $this->assertSame(2, $em2->sample_count);

        // Priced in only the second file: still its own item, at its own rate.
        $conduit = PriceBookItem::where('user_id', $user->id)
            ->where('match_key', PriceBookLine::keyFor('3/4" CONDUIT - EMT'))
            ->sole();
        $this->assertSame('0.7500', $conduit->unit_material_cost);
        $this->assertSame(1, $conduit->sample_count);
    }

    private function seedUniversalItem(float $unitCost, float $laborHours): void
    {
        $import = PriceBookImport::create([
            'user_id' => null,
            'file_name' => 'universal.xlsx',
            'file_hash' => str_repeat('u', 64),
            'material_tax_pct' => 7.5,
            'overhead_pct' => 10,
            'profit_pct' => 12,
            'line_count' => 1,
            'imported_at' => now(),
        ]);

        $description = 'EM2, NEW BATTERY 2/HEAD EM FIXTURE';

        PriceBookLine::create([
            'price_book_import_id' => $import->id,
            'user_id' => null,
            'section' => 'LIGHTING FIXTURES',
            'description' => $description,
            'quantity' => 10,
            'unit' => 'EA',
            'unit_material_cost' => $unitCost,
            'manhour_rate' => 48,
            'unit_manhours' => $laborHours,
            'match_key' => PriceBookLine::keyFor($description),
        ]);

        PriceBookItem::create([
            'user_id' => null,
            'match_key' => PriceBookLine::keyFor($description),
            'unit' => 'EA',
            'description' => $description,
            'section' => 'LIGHTING FIXTURES',
            'unit_material_cost' => $unitCost,
            'unit_manhours' => $laborHours,
            'sample_count' => 1,
            'last_seen_at' => now(),
        ]);
    }

    /**
     * A minimal, real .xlsx with an "Estimate" sheet (one priced line) and a
     * "Bid Recap & Summary" sheet (tax/overhead/profit), in the exact layout
     * PriceBookImporter reads.
     *
     * @param  list<array{0: string, 1: string, 2: float, 3: float, 4: float}>  $lines  description, unit, unit material cost, manhour rate, unit manhours
     */
    private function buildWorkbook(
        array $lines,
        float $overheadPct,
        float $profitPct,
        float $taxPct,
        string $fileName = 'vendor-rates.xlsx',
    ): UploadedFile {
        $path = tempnam(sys_get_temp_dir(), 'pricebook').'.xlsx';

        $writer = new Writer;
        $writer->openToFile($path);

        $estimate = $writer->getCurrentSheet();
        $estimate->setName('Estimate');
        $writer->addRow(Row::fromValues([
            'SR. NO.', 'DWG. NO.', 'DETAIL NO.', 'DESCRIPTION', 'QUANTITY', 'WASTAGE',
            'QTY WITH WASTAGE', 'UNIT', 'UNIT MATERIAL COST', 'MATERIAL COST',
            'MANHOUR RATE', 'UNIT MANHOURS', 'TOTAL MANHOURS', 'MANHOURS COST', 'TOTAL COST',
        ]));

        foreach ($lines as $i => [$description, $unit, $unitCost, $manhourRate, $unitManhours]) {
            $writer->addRow(Row::fromValues([
                (string) ($i + 1), '', '', $description, 1, 0, 1, $unit,
                $unitCost, $unitCost, $manhourRate, $unitManhours, $unitManhours,
                $unitManhours * $manhourRate, $unitCost + $unitManhours * $manhourRate,
            ]));
        }

        $recap = $writer->addNewSheetAndMakeItCurrent();
        $recap->setName('Bid Recap & Summary');
        $writer->addRow(Row::fromValues(['PROJECT NAME: Test Vendor Workbook']));
        $writer->addRow(Row::fromValues(['OVERHEADS @', $overheadPct, 1000.0]));
        $writer->addRow(Row::fromValues(['PROFIT @', $profitPct, 1200.0]));
        $writer->addRow(Row::fromValues(['MATERIAL SALES TAX', $taxPct, 75.0]));

        $writer->close();

        return new UploadedFile(
            $path,
            $fileName,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }
}
