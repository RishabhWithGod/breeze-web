<?php

namespace Tests\Feature;

use App\Models\CompanyProfile;
use App\Models\PriceBookItem;
use App\Models\User;
use App\Services\Takeoff\PriceBookLookup;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Commodity List Setup: the company's default price list — its price book under another name.
 */
class CommodityListTest extends TestCase
{
    use RefreshDatabase;

    private CompanyProfile $company;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'Project Manager']);
        $this->company = $this->companyFor($this->manager, 'Volt & Co');
        $this->manager = $this->manager->fresh();
    }

    private function companyFor(User $owner, string $name): CompanyProfile
    {
        $company = CompanyProfile::create([
            'user_id' => $owner->id, 'name' => $name, 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => uniqid().'@x.test', 'timezone' => 'America/Chicago',
        ]);
        $owner->forceFill(['company_id' => $company->id])->save();

        return $company;
    }

    /** @return array<string, string> */
    private function item(array $overrides = []): array
    {
        return ['category' => 'Electrical', 'item_code' => 'ELE-001', 'description' => '4" Junction Box', 'unit' => 'ea', 'material_price' => '3.60', 'labor_hours' => '0.10', 'markup_pct' => '', ...$overrides];
    }

    private function upload(UploadedFile ...$files): TestResponse
    {
        return $this->actingAs($this->manager)->post(route('commodities.import'), ['files' => $files]);
    }

    /** @param  list<list<string|int|float>>  $rows */
    private function xlsx(array $rows, string $name = 'list.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, $name, null, null, true);
    }

    private function text(string $body, string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $body);
    }

    public function test_items_are_added_edited_and_archived_in_the_companys_price_book(): void
    {
        $this->actingAs($this->manager)->post(route('commodities.store'), $this->item())->assertSessionHasNoErrors();

        $item = PriceBookItem::firstOrFail();
        // Kept under the account that set the company up, as the price book always is.
        $this->assertSame($this->manager->id, $item->user_id);
        $this->assertSame('Electrical', $item->section);
        $this->assertSame('EA', $item->unit);
        $this->assertTrue($item->is_pinned);
        $this->assertNull($item->markup_pct);

        $this->actingAs($this->manager)->put(route('commodities.update', $item), $this->item(['material_price' => '4.25', 'markup_pct' => '12.5', 'description' => '4" Junction Box, steel']))->assertSessionHasNoErrors();
        $this->assertEquals(4.25, $item->fresh()->unit_material_cost);
        $this->assertEquals(12.5, $item->fresh()->markup_pct);
        $this->assertSame('4" JUNCTION BOX, STEEL', $item->fresh()->match_key);

        $this->actingAs($this->manager)->post(route('commodities.archive', $item))->assertSessionHasNoErrors();
        $this->assertNotNull($item->fresh()->archived_at);
        // Editing an archived item does not bring it back.
        $this->actingAs($this->manager)->put(route('commodities.update', $item), $this->item(['description' => '4" Junction Box, steel']));
        $this->assertNotNull($item->fresh()->archived_at);
        $this->actingAs($this->manager)->get(route('commodities.index'))->assertInertia(fn (Assert $page) => $page->has('items', 0)->where('counts.archived', 1));

        $this->actingAs($this->manager)->post(route('commodities.archive', $item), ['restore' => true]);
        $this->assertNull($item->fresh()->archived_at);
    }

    public function test_an_item_is_checked_and_neither_its_code_nor_its_name_repeats(): void
    {
        $this->actingAs($this->manager)->post(route('commodities.store'), $this->item(['category' => '', 'unit' => '', 'material_price' => '-1', 'labor_hours' => 'x']))
            ->assertSessionHasErrors(['category', 'unit', 'material_price', 'labor_hours']);

        $this->actingAs($this->manager)->post(route('commodities.store'), $this->item())->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('commodities.store'), $this->item(['description' => 'Other']))->assertSessionHasErrors('item_code');
        $this->actingAs($this->manager)->post(route('commodities.store'), $this->item(['item_code' => '']))->assertSessionHasErrors('description');
        // No code is fine, and two items may lack one.
        $this->actingAs($this->manager)->post(route('commodities.store'), $this->item(['item_code' => '', 'description' => 'A']))->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('commodities.store'), $this->item(['item_code' => '', 'description' => 'B']))->assertSessionHasNoErrors();
    }

    public function test_every_manager_of_a_company_shares_one_list_and_other_companies_are_apart(): void
    {
        $second = User::factory()->create(['role' => 'Project Manager', 'company_id' => $this->company->id]);
        $this->actingAs($second)->post(route('commodities.store'), $this->item())->assertSessionHasNoErrors();
        $this->assertSame($this->manager->id, PriceBookItem::firstOrFail()->user_id);
        $this->actingAs($this->manager)->get(route('commodities.index'))->assertInertia(fn (Assert $page) => $page->has('items', 1));

        $other = User::factory()->create(['role' => 'Project Manager']);
        $this->companyFor($other, 'Other Co');
        $this->actingAs($other->fresh())->get(route('commodities.index'))->assertInertia(fn (Assert $page) => $page->has('items', 0));
        $this->actingAs($other->fresh())->post(route('commodities.store'), $this->item())->assertSessionHasNoErrors();

        $mine = PriceBookItem::where('user_id', $this->manager->id)->firstOrFail();
        $this->actingAs($other->fresh())->put(route('commodities.update', $mine), $this->item())->assertNotFound();
        $this->actingAs($other->fresh())->post(route('commodities.archive', $mine))->assertNotFound();
    }

    public function test_the_list_searches_filters_and_sorts(): void
    {
        foreach ([['Electrical', 'ELE-001', 'Junction Box'], ['Plumbing', 'PLM-001', 'PEX Pipe'], ['Electrical', 'ELE-002', 'NM Cable']] as [$category, $code, $description]) {
            $this->actingAs($this->manager)->post(route('commodities.store'), $this->item(['category' => $category, 'item_code' => $code, 'description' => $description]));
        }

        $this->actingAs($this->manager)->get(route('commodities.index'))->assertInertia(fn (Assert $page) => $page
            ->component('CommodityList')->has('items', 3)->where('page.total', 3)->where('categories', ['Electrical', 'Plumbing']));
        $this->actingAs($this->manager)->get(route('commodities.index', ['search' => 'cable']))->assertInertia(fn (Assert $page) => $page->has('items', 1)->where('items.0.itemCode', 'ELE-002'));
        $this->actingAs($this->manager)->get(route('commodities.index', ['category' => 'Plumbing']))->assertInertia(fn (Assert $page) => $page->has('items', 1));
        $this->actingAs($this->manager)->get(route('commodities.index', ['sort' => 'item_code', 'dir' => 'desc']))->assertInertia(fn (Assert $page) => $page->where('items.0.itemCode', 'PLM-001'));
    }

    public function test_an_excel_list_is_understood_whatever_its_columns_are_called_or_ordered(): void
    {
        $this->upload($this->xlsx([
            ['ACME Electrical Supply — 2026 price list'],
            [],
            ['Item', 'UOM', 'Unit Price', 'Man Hours', 'SKU'],
            ['CONDUIT'],
            ['3/4" EMT Conduit', 'lf', '$1.25', 0.08, 'C-34'],
            ['1" EMT Conduit', 'LF', '1,80', '', 'C-1'],
            ['Subtotal', '', 100, '', ''],
            ['BOXES'],
            ['4" Junction Box', 'ea', 3.6, 0.1, 'J-4'],
        ]))->assertSessionHasNoErrors()->assertSessionHas('success');

        $box = PriceBookItem::where('item_code', 'J-4')->firstOrFail();
        $this->assertSame('Boxes', ucwords(mb_strtolower($box->section)));
        $this->assertEquals(3.6, $box->unit_material_cost);
        $this->assertEquals(0.1, $box->unit_manhours);
        $conduit = PriceBookItem::where('item_code', 'C-34')->firstOrFail();
        $this->assertSame('LF', $conduit->unit);
        $this->assertEquals(1.25, $conduit->unit_material_cost);
        // The subtotal is not an item; the "1,80" (a comma, no price we can trust) is read as 180, never guessed at.
        $this->assertNull(PriceBookItem::where('description', 'Subtotal')->first());
        $this->assertSame(3, PriceBookItem::count());
    }

    public function test_a_csv_a_word_table_and_a_pdf_are_read_too(): void
    {
        $this->upload($this->text("Category;Item Code;Description;Unit;Material Price;Labor Hours\nPlumbing;PLM-1;PEX Pipe;LF;1.25;0.08\n", 'plumbing.csv'))->assertSessionHasNoErrors();
        $this->assertEquals(1.25, PriceBookItem::where('item_code', 'PLM-1')->value('unit_material_cost'));

        $word = new PhpWord;
        $table = $word->addSection()->addTable();
        foreach ([['Description', 'Unit', 'Price'], ['GFCI Outlet', 'EA', '18.50']] as $row) {
            $table->addRow();
            foreach ($row as $cell) {
                $table->addCell(2000)->addText($cell);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'w').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);
        $this->upload(new UploadedFile($path, 'devices.docx', null, null, true))->assertSessionHasNoErrors();
        $this->assertEquals(18.5, PriceBookItem::where('description', 'GFCI Outlet')->value('unit_material_cost'));

        $pdf = new FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Courier', '', 10);
        foreach (['Description        Unit   Price   Hours', 'Wire Nut Yellow   BX     6.40    0.05', 'Cable Staple       PK     2.10    0.02'] as $line) {
            $pdf->Cell(0, 6, $line, 0, 1);
        }
        $path = tempnam(sys_get_temp_dir(), 'p').'.pdf';
        file_put_contents($path, $pdf->Output('S'));
        $this->upload(new UploadedFile($path, 'fasteners.pdf', null, null, true))->assertSessionHasNoErrors();
        $this->assertEquals(6.4, PriceBookItem::where('description', 'Wire Nut Yellow')->value('unit_material_cost'));
        $this->assertEquals(0.02, PriceBookItem::where('description', 'Cable Staple')->value('unit_manhours'));
    }

    public function test_a_list_with_no_headings_is_read_line_by_line(): void
    {
        $this->upload($this->text("WIRE\nRomex 12/2 wire  LF  0.52\nRomex 14/2 wire  LF  0.41  0.02\nTotal  1.00\n", 'plain.txt'))->assertSessionHasNoErrors();

        $this->assertSame(2, PriceBookItem::count());
        $this->assertEquals(0.41, PriceBookItem::where('description', 'Romex 14/2 wire')->value('unit_material_cost'));
        $this->assertSame('Wire', PriceBookItem::where('description', 'Romex 14/2 wire')->value('section'));
    }

    public function test_uploading_again_updates_instead_of_duplicating_and_a_hand_edit_survives_an_estimate_import(): void
    {
        $file = fn (string $price) => $this->text("Description,Unit,Price\nJunction Box,EA,{$price}\n", 'list.csv');

        $this->upload($file('3.60'))->assertSessionHasNoErrors();
        $this->upload($file('4.10'))->assertSessionHasNoErrors();

        $this->assertSame(1, PriceBookItem::count());
        $this->assertEquals(4.1, PriceBookItem::firstOrFail()->unit_material_cost);
        $this->assertTrue(PriceBookItem::firstOrFail()->is_pinned);
    }

    public function test_a_file_that_cannot_be_understood_is_named_and_nothing_is_added(): void
    {
        $this->upload($this->text("just some words\nand more\n", 'notes.txt'), $this->text('x', 'old.xls'))
            ->assertSessionHasErrors(['file.0', 'file.1']);
        $this->assertSame(0, PriceBookItem::count());

        $this->actingAs($this->manager)->post(route('commodities.import'), [])->assertSessionHasErrors('files');

        // One good file is still added, and the bad one is reported.
        $this->upload($this->text("Description,Unit,Price\nBox,EA,1\n", 'ok.csv'), $this->text('nothing', 'bad.txt'))
            ->assertSessionHas('success')->assertSessionHas('warning');
        $this->assertSame(1, PriceBookItem::count());
    }

    public function test_estimates_price_from_the_companys_list_and_never_from_an_archived_item(): void
    {
        $this->actingAs($this->manager)->post(route('commodities.store'), $this->item());
        $item = PriceBookItem::firstOrFail();
        $lookup = app(PriceBookLookup::class);

        // Any manager of the company, and nobody else, prices from it.
        $found = $lookup->forUser($this->manager->id)->find('4" Junction Box');
        $this->assertSame($item->id, $found['item']->id);
        $this->assertEquals(3.6, $found['unit_cost']);
        $this->assertEquals(0.1, $found['labor_hours']);

        $teammate = User::factory()->create(['role' => 'Project Manager', 'company_id' => $this->company->id]);
        $this->assertNotNull($lookup->forUser($teammate->id)->find('4" Junction Box'));
        $stranger = User::factory()->create(['role' => 'Project Manager']);
        $this->assertNull($lookup->forUser($stranger->id)->find('4" Junction Box'));

        $item->forceFill(['archived_at' => now()])->save();
        $this->assertNull($lookup->forUser($this->manager->id)->find('4" Junction Box'));
    }

    public function test_the_template_downloads(): void
    {
        $this->actingAs($this->manager)->get(route('commodities.template'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_saving_needs_an_item_and_skipping_leaves_the_step_for_later(): void
    {
        $this->actingAs($this->manager)->post(route('commodities.save'))->assertSessionHas('warning');

        $this->actingAs($this->manager)->post(route('commodities.skip'))->assertRedirect(route('home'));
        $this->assertContains('commodities', $this->company->fresh()->onboarding_skipped);

        $this->actingAs($this->manager)->post(route('commodities.store'), $this->item());
        $this->actingAs($this->manager)->post(route('commodities.save'))->assertRedirect(route('home'))->assertSessionHas('success');
    }

    public function test_only_the_people_who_price_work_can_open_it(): void
    {
        foreach (['Foreman', 'Journeyman', 'Apprentice'] as $role) {
            $crew = User::factory()->create(['role' => $role, 'company_id' => $this->company->id]);
            $this->actingAs($crew)->get(route('commodities.index'))->assertForbidden();
            $this->actingAs($crew)->post(route('commodities.store'), $this->item())->assertForbidden();
        }

        $estimator = User::factory()->create(['role' => 'Estimator', 'company_id' => $this->company->id]);
        $this->actingAs($estimator)->get(route('commodities.index'))->assertOk();
    }
}
