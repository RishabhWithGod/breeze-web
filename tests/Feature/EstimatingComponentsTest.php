<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\User;
use App\Services\Takeoff\EstimatingComponents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the review screen says the estimate will get from a takeoff.
 *
 * The rule this holds to: a figure shown is one the engine actually returned.
 * Anything the pipeline does not produce yet is listed as pending rather than
 * filled with a zero or a placeholder number, because a reviewer signing a
 * takeoff off has to be able to trust every number on the screen.
 */
class EstimatingComponentsTest extends TestCase
{
    use RefreshDatabase;

    private AiResult $result;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $project = $user->projects()->create([
            'name' => 'Harborview Data Hall',
            'client' => 'Harborview Data Hall',
            'status' => 'completed',
        ]);
        $aiJob = AiJob::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'completed',
        ]);
        $this->result = AiResult::create([
            'ai_job_id' => $aiJob->id,
            'project_id' => $project->id,
            'original_payload' => [],
        ]);
    }

    public function test_a_run_that_returned_nothing_lists_every_component_as_pending(): void
    {
        $components = collect(app(EstimatingComponents::class)->for($this->result));

        $this->assertTrue(
            $components->every(fn ($component) => $component['status'] === 'pending'),
            'Nothing was returned, so nothing may claim to be from the takeoff.',
        );

        // Every component the estimate needs is still named, so the reviewer
        // knows what is coming rather than only what is here.
        $this->assertEqualsCanonicalizing(
            ['labor', 'wire-length', 'wire-size', 'conduit', 'bends', 'equipment', 'circuits', 'panels'],
            $components->pluck('key')->all(),
        );
    }

    public function test_no_pending_component_carries_a_made_up_figure(): void
    {
        $components = collect(app(EstimatingComponents::class)->for($this->result))
            ->where('status', 'pending');

        foreach ($components as $component) {
            $this->assertSame([], $component['items'], "{$component['key']} invented rows");
            $this->assertSame(0, $component['moreCount']);
        }
    }

    public function test_wire_sizes_the_engine_returned_are_shown_as_real_data(): void
    {
        $this->result->wireSizes()->create([
            'page' => 2, 'size' => '#12 AWG', 'context' => 'Branch circuits', 'count' => 14, 'position' => 0,
        ]);

        $wire = collect(app(EstimatingComponents::class)->for($this->result))->firstWhere('key', 'wire-size');

        $this->assertSame('available', $wire['status']);
        $this->assertSame('1 size found', $wire['summary']);
        $this->assertSame('#12 AWG', $wire['items'][0]['label']);
        $this->assertSame('Branch circuits', $wire['items'][0]['detail']);
        $this->assertSame('14×', $wire['items'][0]['value']);
        $this->assertSame(2, $wire['items'][0]['page']);
    }

    public function test_labor_only_appears_once_the_reviewed_bill_of_quantities_prices_it(): void
    {
        $before = collect(app(EstimatingComponents::class)->for($this->result))->firstWhere('key', 'labor');
        $this->assertSame('pending', $before['status']);

        $this->result->update(['final_payload' => ['boq' => ['totals' => ['labor_hours' => 37.5]]]]);

        $after = collect(app(EstimatingComponents::class)->for($this->result->refresh()))
            ->firstWhere('key', 'labor');

        $this->assertSame('available', $after['status']);
        $this->assertSame('37.5 hours', $after['summary']);
    }

    public function test_a_long_list_is_capped_and_says_how_many_are_left(): void
    {
        foreach (range(1, 11) as $index) {
            $this->result->wireSizes()->create([
                'page' => 1, 'size' => "#{$index} AWG", 'context' => 'Branch circuits', 'count' => 1, 'position' => $index,
            ]);
        }

        $wire = collect(app(EstimatingComponents::class)->for($this->result))->firstWhere('key', 'wire-size');

        // A summary beside the review, not the full table.
        $this->assertCount(8, $wire['items']);
        $this->assertSame(3, $wire['moreCount']);
    }
}
