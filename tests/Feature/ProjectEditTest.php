<?php

namespace Tests\Feature;

use App\Models\ProjectRateItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Edit Project — the same screen and the same fields as Add Project, filled
 * in with what is already on record rather than typed fresh.
 */
class ProjectEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_edit_screen_shows_the_projects_current_details(): void
    {
        $user = User::factory()->create();
        $client = $user->clients()->create(['name' => 'Harborview Electric']);
        $project = $user->projects()->create([
            'client_id' => $client->id,
            'name' => 'Harborview Phase 2',
            'client' => $client->name,
            'status' => 'draft',
            'review_status' => 'none',
            'estimate_target_total' => 24850,
        ]);

        $this->actingAs($user)
            ->get("/projects/{$project->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->component('ProjectEdit')
                ->where('project.id', $project->id)
                ->where('project.name', 'Harborview Phase 2')
                ->where('project.clientId', $client->id)
                ->where('project.estimateTargetTotal', '24850.00')
                ->has('clients'));
    }

    public function test_a_project_can_be_updated(): void
    {
        $user = User::factory()->create();
        $original = $user->clients()->create(['name' => 'Harborview Electric']);
        $renamed = $user->clients()->create(['name' => 'Northgate Electric']);
        $renamed->addresses()->create([
            'address' => 'Northgate, Seattle',
            'is_primary' => true,
            'position' => 0,
        ]);

        $project = $user->projects()->create([
            'client_id' => $original->id,
            'name' => 'Old Name',
            'client' => $original->name,
            'status' => 'draft',
            'review_status' => 'none',
        ]);

        $this->actingAs($user)
            ->put("/projects/{$project->id}", [
                'client_id' => $renamed->id,
                'name' => 'Northgate Fit-out',
                'estimate_target_total' => '18500',
            ])
            ->assertRedirect(route('projects.show', $project))
            ->assertSessionHas('success');

        $project->refresh();

        $this->assertSame($renamed->id, $project->client_id);
        $this->assertSame('Northgate Fit-out', $project->name);
        // The client name snapshot follows the newly picked client.
        $this->assertSame('Northgate Electric', $project->client);
        // The site follows the newly picked client's primary address too.
        $this->assertSame('Northgate, Seattle', $project->location);
        $this->assertSame('18500.00', $project->estimate_target_total);
    }

    public function test_editing_a_project_validates_its_fields(): void
    {
        $user = User::factory()->create();
        $client = $user->clients()->create(['name' => 'Harborview Electric']);
        $project = $user->projects()->create([
            'client_id' => $client->id,
            'name' => 'Harborview Phase 2',
            'client' => $client->name,
            'status' => 'draft',
            'review_status' => 'none',
        ]);

        $this->actingAs($user)
            ->put("/projects/{$project->id}", ['name' => 'ab', 'client_id' => ''])
            ->assertSessionHasErrors(['name', 'client_id']);

        $this->assertSame('Harborview Phase 2', $project->fresh()->name);
    }

    public function test_a_project_belonging_to_someone_else_cannot_be_edited(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => 'Harborview Electric']);
        $project = $owner->projects()->create([
            'client_id' => $client->id,
            'name' => 'Harborview Phase 2',
            'client' => $client->name,
            'status' => 'draft',
            'review_status' => 'none',
        ]);

        $other = User::factory()->create();

        $this->actingAs($other)->get("/projects/{$project->id}/edit")->assertForbidden();
        $this->actingAs($other)
            ->put("/projects/{$project->id}", ['name' => 'Hijacked', 'client_id' => $client->id])
            ->assertForbidden();

        $this->assertSame('Harborview Phase 2', $project->fresh()->name);
    }

    public function test_updating_a_project_can_add_more_vendor_rate_lists(): void
    {
        $user = User::factory()->create();
        $client = $user->clients()->create(['name' => 'Harborview Electric']);
        $project = $user->projects()->create([
            'client_id' => $client->id,
            'name' => 'Harborview Phase 2',
            'client' => $client->name,
            'status' => 'draft',
            'review_status' => 'none',
        ]);

        $workbook = $this->buildWorkbook([
            ['EM2, NEW BATTERY 2/HEAD EM FIXTURE', 'EA', 60.0, 48, 1.25],
        ]);

        $this->actingAs($user)
            ->put("/projects/{$project->id}", [
                'client_id' => $client->id,
                'name' => $project->name,
                'vendor_rate_list' => [$workbook],
            ])
            ->assertSessionHas('success')
            ->assertSessionMissing('warning');

        $this->assertSame(
            1,
            ProjectRateItem::where('project_id', $project->id)->count(),
        );
    }

    /**
     * A minimal, real .xlsx with an "Estimate" sheet (one priced line) —
     * exactly the layout the vendor rate list importer reads.
     *
     * @param  list<array{0: string, 1: string, 2: float, 3: float, 4: float}>  $lines
     */
    private function buildWorkbook(array $lines): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ratelist').'.xlsx';

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

        $writer->close();

        return new UploadedFile(
            $path,
            'vendor-rates.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }
}
