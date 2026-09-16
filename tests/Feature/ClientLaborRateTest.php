<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Client;
use App\Models\EstimateItem;
use App\Models\ProjectRateImport;
use App\Models\ProjectRateItem;
use App\Models\ProjectRateLine;
use App\Models\User;
use App\Services\Estimating\WorkbookReader;
use App\Services\Takeoff\EstimateBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A client's own labor rate — set on their record, not typed on an estimate.
 *
 * The Client form offers it pre-filled with the configured default, so
 * leaving it alone is a decision rather than an oversight. Once set, it
 * prices every labor line on every estimate raised against that client's
 * projects, regardless of what any project's own rate list or the price
 * book has to say about the hour — that is the whole point of setting it.
 */
class ClientLaborRateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_the_create_screen_offers_the_configured_default(): void
    {
        $this->actingAs($this->user)
            ->get('/clients/create')
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClientCreate')
                ->where('defaultLaborRate', fn ($value) => (float) $value === (float) config('ai.estimating.labor_rate')));
    }

    public function test_a_client_can_be_created_with_a_labor_rate(): void
    {
        $this->actingAs($this->user)->post('/clients', [
            'name' => 'Harborview Electric',
            'labor_rate' => '75',
        ]);

        $client = Client::where('name', 'Harborview Electric')->sole();

        $this->assertSame('75.00', $client->labor_rate);
    }

    public function test_a_client_can_be_created_without_a_labor_rate(): void
    {
        $this->actingAs($this->user)->post('/clients', [
            'name' => 'Harborview Electric',
            'labor_rate' => '',
        ]);

        $client = Client::where('name', 'Harborview Electric')->sole();

        $this->assertNull($client->labor_rate);
        $this->assertSame((float) config('ai.estimating.labor_rate'), $client->effectiveLaborRate());
    }

    public function test_the_edit_screen_shows_the_configured_default_when_none_is_set(): void
    {
        $client = $this->user->clients()->create(['name' => 'Harborview Electric']);

        $this->actingAs($this->user)
            ->get("/clients/{$client->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClientEdit')
                ->where('client.laborRate', fn ($value) => (float) $value === (float) config('ai.estimating.labor_rate')));
    }

    public function test_a_clients_labor_rate_can_be_updated(): void
    {
        $client = $this->user->clients()->create(['name' => 'Harborview Electric', 'labor_rate' => 50]);

        $this->actingAs($this->user)
            ->put("/clients/{$client->id}", [
                'name' => 'Harborview Electric',
                'labor_rate' => '82.50',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('82.50', $client->fresh()->labor_rate);
    }

    /**
     * The exact figure the client's record carries, on every labor line —
     * even when this project's own rate list bid the hour at something else.
     */
    public function test_a_clients_own_labor_rate_prices_every_labor_line_on_its_estimates(): void
    {
        $client = $this->user->clients()->create(['name' => 'Harborview Electric', 'labor_rate' => 82.50]);
        [$result, $projectId] = $this->makeTakeoffAgainst($client);
        $this->seedRateBook($projectId, laborRate: 48.0);

        $result->finalSymbols()->create([
            'project_id' => $projectId,
            'name' => 'EM2',
            'count' => 4,
            'confidence' => 0.9,
        ]);

        $estimate = app(EstimateBuilder::class)->fromFinalJson($result, $this->user);
        $labor = $estimate->items()->where('category', EstimateItem::CATEGORY_LABOR)->sole();

        // The client's own rate, not the $48/hr the project's own rate list bid.
        $this->assertSame('82.5000', $labor->unit_cost);
    }

    /** Without a client override, the usual chain still applies exactly as before. */
    public function test_without_a_client_labor_rate_the_projects_own_rate_list_still_applies(): void
    {
        $client = $this->user->clients()->create(['name' => 'Harborview Electric']);
        [$result, $projectId] = $this->makeTakeoffAgainst($client);
        $this->seedRateBook($projectId, laborRate: 48.0);

        $result->finalSymbols()->create([
            'project_id' => $projectId,
            'name' => 'EM2',
            'count' => 4,
            'confidence' => 0.9,
        ]);

        $estimate = app(EstimateBuilder::class)->fromFinalJson($result, $this->user);
        $labor = $estimate->items()->where('category', EstimateItem::CATEGORY_LABOR)->sole();

        $this->assertSame('48.0000', $labor->unit_cost);
    }

    /** @return array{0: AiResult, 1: int} */
    private function makeTakeoffAgainst(Client $client): array
    {
        $project = $this->user->projects()->create([
            'client_id' => $client->id,
            'name' => 'Harborview Data Hall',
            'client' => $client->name,
            'status' => 'completed',
        ]);

        $aiJob = AiJob::create([
            'project_id' => $project->id,
            'user_id' => $this->user->id,
            'status' => 'completed',
        ]);

        $result = AiResult::create([
            'ai_job_id' => $aiJob->id,
            'project_id' => $project->id,
            'original_payload' => [],
            'final_payload' => ['final_counts' => []],
        ]);

        return [$result, $project->id];
    }

    private function seedRateBook(int $projectId, float $laborRate): void
    {
        $import = ProjectRateImport::create([
            'project_id' => $projectId,
            'file_name' => 'Electrical Estimate - TEST.xlsx',
            'file_hash' => bin2hex(random_bytes(32)),
            'line_count' => 1,
            'imported_at' => now(),
        ]);

        $description = 'EM2, NEW BATTERY 2/HEAD EM FIXTURE';

        ProjectRateLine::create([
            'project_rate_import_id' => $import->id,
            'project_id' => $projectId,
            'description' => $description,
            'quantity' => 10,
            'unit' => 'EA',
            'unit_material_cost' => 60.0,
            'manhour_rate' => $laborRate,
            'unit_manhours' => 1.25,
            'match_key' => WorkbookReader::keyFor($description),
        ]);

        ProjectRateItem::create([
            'project_id' => $projectId,
            'match_key' => WorkbookReader::keyFor($description),
            'unit' => 'EA',
            'description' => $description,
            'unit_material_cost' => 60.0,
            'unit_manhours' => 1.25,
            'sample_count' => 1,
            'min_material_cost' => 60.0,
            'max_material_cost' => 60.0,
            'last_seen_at' => now(),
        ]);
    }
}
