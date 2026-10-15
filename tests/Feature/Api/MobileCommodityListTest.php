<?php

namespace Tests\Feature\Api;

use App\Models\CompanyProfile;
use App\Models\PriceBookItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The app's Commodity List follows web's own rules: the people who price
 * work may use it, items are kept (archived, not deleted), and an item is
 * unique by code and by description + unit.
 */
class MobileCommodityListTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'Project Manager', 'status' => User::STATUS_ACTIVE]);
        $company = CompanyProfile::create([
            'user_id' => $this->manager->id, 'name' => 'Volt & Co', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        $this->manager->forceFill(['company_id' => $company->id])->save();
        $this->manager = $this->manager->fresh();
    }

    private function api(?User $as = null): self
    {
        return $this->withToken(($as ?? $this->manager)->createToken('test')->plainTextToken);
    }

    /** @return array<string, string> */
    private function item(array $overrides = []): array
    {
        return ['category' => 'Electrical', 'item_code' => 'ELE-001', 'description' => '4" Junction Box', 'unit' => 'ea', 'material_price' => '3.60', 'labor_hours' => '0.10', ...$overrides];
    }

    public function test_an_item_is_added_listed_edited_and_archived(): void
    {
        $id = $this->api()->postJson('/api/v1/office/commodities', $this->item())
            ->assertCreated()
            ->assertJsonPath('data.unit', 'EA')
            ->assertJsonPath('data.materialPrice', 3.6)
            ->json('data.id');

        $this->api()->getJson('/api/v1/office/commodities')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.categories.0', 'Electrical')
            ->assertJsonPath('data.counts.active', 1);

        $this->api()->putJson("/api/v1/office/commodities/{$id}", $this->item(['material_price' => '4.25', 'markup_pct' => '12.5']))
            ->assertOk()
            ->assertJsonPath('data.materialPrice', 4.25)
            ->assertJsonPath('data.markupPct', 12.5);

        $this->api()->postJson("/api/v1/office/commodities/{$id}/archive")
            ->assertOk()
            ->assertJsonPath('data.archived', true);
        $this->api()->getJson('/api/v1/office/commodities')->assertJsonCount(0, 'data.items');
        $this->api()->getJson('/api/v1/office/commodities?archived=1')->assertJsonCount(1, 'data.items');

        $this->api()->postJson("/api/v1/office/commodities/{$id}/archive", ['restore' => true])
            ->assertJsonPath('data.archived', false);
    }

    public function test_search_category_and_sort_run_on_the_server(): void
    {
        $this->api()->postJson('/api/v1/office/commodities', $this->item())->assertCreated();
        $this->api()->postJson('/api/v1/office/commodities', $this->item(['category' => 'Lighting', 'item_code' => 'LTG-1', 'description' => 'LED Panel']))->assertCreated();

        $this->api()->getJson('/api/v1/office/commodities?search=LED')->assertJsonCount(1, 'data.items');
        $this->api()->getJson('/api/v1/office/commodities?category=Electrical')->assertJsonCount(1, 'data.items');
        $this->api()->getJson('/api/v1/office/commodities?sort=description&dir=desc')
            ->assertJsonPath('data.items.0.description', 'LED Panel');
    }

    public function test_duplicates_are_refused(): void
    {
        $this->api()->postJson('/api/v1/office/commodities', $this->item())->assertCreated();

        $this->api()->postJson('/api/v1/office/commodities', $this->item(['description' => 'Something else']))
            ->assertUnprocessable()->assertJsonValidationErrors('item_code');
        $this->api()->postJson('/api/v1/office/commodities', $this->item(['item_code' => 'ELE-002']))
            ->assertUnprocessable()->assertJsonValidationErrors('description');
    }

    public function test_a_csv_list_is_read_and_added(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'c').'.csv';
        file_put_contents($path, "Category,Item Code,Description,Unit,Price,Hours\nElectrical,ELE-009,Duplex Receptacle,EA,2.40,0.25\n");

        $this->api()->post('/api/v1/office/commodities/import', [
            'files' => [new UploadedFile($path, 'list.csv', 'text/csv', null, true)],
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        $this->assertSame(1, PriceBookItem::where('description', 'Duplex Receptacle')->count());
    }

    public function test_only_the_people_who_price_work_may_use_it(): void
    {
        $crew = User::factory()->create(['role' => 'Journeyman', 'status' => User::STATUS_ACTIVE, 'company_id' => $this->manager->company_id]);

        $this->api($crew)->getJson('/api/v1/office/commodities')->assertForbidden();
        $this->api($crew)->postJson('/api/v1/office/commodities', $this->item())->assertForbidden();
    }
}
