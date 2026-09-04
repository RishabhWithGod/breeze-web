<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Project;
use App\Models\SymbolReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The spatial view of a takeoff's drawing.
 *
 * It is a second window onto the review's own data, not a second system: the
 * same page images, the same occurrence coordinates, the same categories. Every
 * edit it offers posts to a review endpoint, so the review stays the one place
 * a symbol's status, quantity and position are decided.
 *
 * These tests pin the two things that would make it dangerous rather than
 * merely broken: inventing a page size, and drifting away from the review.
 */
class ThreeDViewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AiResult $result;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'Project Manager']);
        $client = $this->user->clients()->create(['name' => 'Harborview']);
        $project = Project::create([
            'user_id' => $this->user->id,
            'client_id' => $client->id,
            'name' => 'Data Hall',
            'client' => 'Harborview',
            'status' => 'draft',
        ]);
        $upload = $project->uploads()->create([
            'user_id' => $this->user->id,
            'name' => 'E-101.pdf',
            'format' => 'PDF',
            'size_bytes' => 1024,
            'status' => 'completed',
        ]);

        $this->result = AiResult::create([
            'ai_job_id' => AiJob::create([
                'project_id' => $project->id,
                'upload_id' => $upload->id,
                'user_id' => $this->user->id,
                'status' => 'completed',
            ])->id,
            'project_id' => $project->id,
            'upload_id' => $upload->id,
            'original_payload' => [],
            'page_count' => 2,
            // What the engine actually measured. Page 2 is deliberately absent.
            'page_sizes' => [1 => ['width' => 2400, 'height' => 1800]],
        ]);
    }

    private function symbol(string $name, array $occurrences): SymbolReview
    {
        return $this->result->reviews()->create([
            'project_id' => $this->result->project_id,
            'name' => $name,
            'ai_name' => $name,
            'ai_count' => count($occurrences),
            'final_count' => count($occurrences),
            'status' => 'approved',
            'page' => $occurrences[0]['page'] ?? 1,
            'occurrences' => $occurrences,
        ]);
    }

    /* ------------------------------------------------------- the way in -- */

    public function test_the_review_screen_links_to_the_spatial_view(): void
    {
        // The link is rendered client-side from this id, so what the route has
        // to guarantee is that the address exists and resolves.
        $this->assertSame(
            url("/reviews/{$this->result->id}/3d"),
            route('reviews.threeD', $this->result),
        );
    }

    public function test_the_route_opens_and_loads_that_review(): void
    {
        $this->actingAs($this->user)
            ->get(route('reviews.threeD', $this->result))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ThreeDView')
                ->where('result.id', $this->result->id)
                ->where('result.pageCount', 2));
    }

    public function test_it_is_refused_to_someone_elses_takeoff(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('reviews.threeD', $this->result))
            ->assertForbidden();
    }

    /* ------------------------------------------------------ coordinates -- */

    /**
     * The engine's own page sizes, and only those.
     *
     * A page it never measured is absent rather than defaulted. The viewer says
     * so and draws nothing: a symbol placed against a guessed page size looks
     * exactly like one in the right place, which is the worst outcome available.
     */
    public function test_only_measured_pages_carry_dimensions(): void
    {
        $this->actingAs($this->user)
            ->get(route('reviews.threeD', $this->result))
            ->assertInertia(function (Assert $page) {
                $dimensions = $page->toArray()['props']['pageDimensions'];

                // The engine's numbers, unrounded and untouched. JSON drops a
                // float's trailing zero, so the comparison is by value.
                $this->assertEqualsWithDelta(2400, $dimensions[1]['width'], 0.001);
                $this->assertEqualsWithDelta(1800, $dimensions[1]['height'], 0.001);
                $this->assertArrayNotHasKey(2, $dimensions);
                // No 3000x2200, no viewport size, no rounding to something tidy.
                $this->assertCount(1, $dimensions);
            });
    }

    public function test_it_carries_the_real_occurrence_coordinates(): void
    {
        $this->symbol('Wall Mount', [
            ['key' => 'det_a', 'bbox' => [1301, 1840, 13, 13], 'page' => 1, 'status' => 'approved', 'confidence' => 0.73],
        ]);

        $this->actingAs($this->user)
            ->get(route('reviews.threeD', $this->result))
            ->assertInertia(fn (Assert $page) => $page
                ->where('overlaySymbols.0.occurrences.0.bbox', [1301, 1840, 13, 13])
                ->where('overlaySymbols.0.occurrences.0.key', 'det_a'));
    }

    /**
     * A symbol on two pages appears on both, from its own occurrences — never
     * copied forward onto page one.
     */
    public function test_a_multi_page_symbol_keeps_its_pages(): void
    {
        $this->symbol('Duplex Outlet', [
            ['key' => 'det_p1', 'bbox' => [10, 10, 8, 8], 'page' => 1, 'status' => 'approved', 'confidence' => 0.9],
            ['key' => 'det_p2', 'bbox' => [20, 20, 8, 8], 'page' => 2, 'status' => 'approved', 'confidence' => 0.9],
        ]);

        $this->actingAs($this->user)
            ->get(route('reviews.threeD', $this->result))
            ->assertInertia(function (Assert $page) {
                $pages = array_column(
                    $page->toArray()['props']['overlaySymbols'][0]['occurrences'],
                    'page',
                );

                $this->assertSame([1, 2], $pages);
            });
    }

    /* ------------------------------------------------------- the review -- */

    /** Colours are resolved from this list, the same one the review resolves from. */
    public function test_it_carries_every_category_for_the_colour_map(): void
    {
        $this->symbol('Wall Mount', [['key' => 'a', 'bbox' => [1, 1, 4, 4], 'page' => 1, 'status' => 'approved', 'confidence' => 1]]);
        $this->symbol('Thermostat', [['key' => 'b', 'bbox' => [2, 2, 4, 4], 'page' => 1, 'status' => 'approved', 'confidence' => 1]]);

        $this->actingAs($this->user)
            ->get(route('reviews.threeD', $this->result))
            ->assertInertia(fn (Assert $page) => $page
                ->where('distinctNames', ['Thermostat', 'Wall Mount']));
    }

    /** A finalised takeoff is read-only here, exactly as it is on the review. */
    public function test_a_finalised_takeoff_is_read_only(): void
    {
        $this->actingAs($this->user)
            ->get(route('reviews.threeD', $this->result))
            ->assertInertia(fn (Assert $page) => $page->where('canEdit', true));

        $this->result->update(['finalised_at' => now(), 'review_status' => 'finalised']);

        $this->actingAs($this->user)
            ->get(route('reviews.threeD', $this->result))
            ->assertInertia(fn (Assert $page) => $page->where('canEdit', false));
    }

    /**
     * Moving a marker writes through the review's own endpoint — the same one
     * the review overlay uses, with the same identity and the same audit trail.
     */
    public function test_moving_a_marker_persists_through_the_review(): void
    {
        $symbol = $this->symbol('Wall Mount', [
            ['key' => 'det_a', 'bbox' => [100, 100, 12, 12], 'page' => 1, 'status' => 'approved', 'confidence' => 0.9],
        ]);

        $this->actingAs($this->user)
            ->post(route('reviews.occurrenceMove', [$this->result, $symbol, 'det_a']), [
                'bbox' => [400, 300, 12, 12],
            ])
            ->assertSessionHasNoErrors();

        $occurrence = $symbol->fresh()->occurrences[0];

        $this->assertSame([400.0, 300.0, 12.0, 12.0], array_map('floatval', $occurrence['bbox']));
        // Same occurrence, not a copy — and the original position is kept.
        $this->assertSame('det_a', $occurrence['key']);
        $this->assertSame([100, 100, 12, 12], $occurrence['original_bbox']);
        $this->assertCount(1, $symbol->fresh()->occurrences);
    }

    /** Quantity goes through the review's count endpoint, not a second one. */
    public function test_quantity_goes_through_the_review(): void
    {
        $symbol = $this->symbol('Wall Mount', [
            ['key' => 'det_a', 'bbox' => [1, 1, 4, 4], 'page' => 1, 'status' => 'approved', 'confidence' => 1],
        ]);

        $this->actingAs($this->user)
            ->post(route('reviews.count', [$this->result, $symbol]), ['step' => 1])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $symbol->fresh()->final_count);
    }

    /** And so does rejecting one occurrence. */
    public function test_rejecting_an_occurrence_goes_through_the_review(): void
    {
        $symbol = $this->symbol('Wall Mount', [
            ['key' => 'det_a', 'bbox' => [1, 1, 4, 4], 'page' => 1, 'status' => 'approved', 'confidence' => 1],
        ]);

        $this->actingAs($this->user)
            ->post(route('reviews.occurrence', [$this->result, $symbol, 'det_a']))
            ->assertSessionHasNoErrors();

        $this->assertSame('rejected', $symbol->fresh()->occurrences[0]['status']);
    }

    /* ------------------------------------------------------- no damage --- */

    /**
     * The review screen still renders everything it did.
     *
     * The only change made to it was a link; this is what proves the link did
     * not cost anything else.
     */
    public function test_the_review_screen_is_unchanged(): void
    {
        $this->symbol('Wall Mount', [
            ['key' => 'det_a', 'bbox' => [1, 1, 4, 4], 'page' => 1, 'status' => 'approved', 'confidence' => 1],
        ]);

        $this->actingAs($this->user)
            ->get(route('reviews.show', $this->result))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AiReview')
                ->has('symbols')
                ->has('overlaySymbols')
                ->has('pageDimensions')
                ->has('tally')
                ->has('estimating')
                ->has('distinctNames')
                ->has('history'));
    }

    /** The spatial view adds no write endpoints of its own. */
    public function test_the_feature_has_no_write_routes(): void
    {
        $own = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_contains($route->getActionName(), 'ThreeDViewController'));

        $this->assertCount(1, $own);
        $this->assertSame(['GET', 'HEAD'], $own->first()->methods());
    }

    /* --------------------------------------------- what phase 3 added ---- */

    /**
     * Deleting is only offered where the review allows it.
     *
     * A manually added symbol can go; an AI detection is rejected instead, so
     * the record of what the engine found survives the review of it. The rule is
     * the server's — the viewer only stops offering a button that would be
     * refused.
     */
    public function test_a_manual_symbol_can_be_removed_and_a_detection_cannot(): void
    {
        $manual = $this->symbol('Added By Hand', [
            ['key' => 'man_a', 'bbox' => [5, 5, 8, 8], 'page' => 1, 'status' => 'approved', 'confidence' => 1, 'origin' => 'manual'],
        ]);
        $manual->update(['origin' => 'manual']);

        $detected = $this->symbol('Wall Mount', [
            ['key' => 'det_a', 'bbox' => [9, 9, 8, 8], 'page' => 1, 'status' => 'approved', 'confidence' => 0.9, 'origin' => 'ai'],
        ]);

        $this->actingAs($this->user)
            ->delete(route('reviews.occurrenceDelete', [$this->result, $manual, 'man_a']))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->user)
            ->delete(route('reviews.occurrenceDelete', [$this->result, $detected, 'det_a']))
            ->assertForbidden();

        // The detection is untouched by the refusal.
        $this->assertCount(1, $detected->fresh()->occurrences);
    }

    /** Adding by hand goes through the review's own endpoint. */
    public function test_adding_a_symbol_by_hand_goes_through_the_review(): void
    {
        $this->actingAs($this->user)
            ->post(route('reviews.manualAdd', $this->result), [
                'page' => 1,
                'name' => 'Wall Mount',
                'bbox' => [1200, 900, 30, 30],
            ])
            ->assertSessionHasNoErrors();

        $review = SymbolReview::where('name', 'Wall Mount')->sole();
        $occurrence = $review->occurrences[0];

        $this->assertSame('manual', $occurrence['origin']);
        $this->assertSame(1, $occurrence['page']);
        $this->assertSame([1200, 900, 30, 30], $occurrence['bbox']);
        $this->assertSame(1, $review->final_count);
    }

    /**
     * The camera never reaches the database.
     *
     * Zoom, pan, rotation and tilt are view state held in the browser; there is
     * no endpoint that accepts any of them, and nothing on the server stores
     * one. This asserts the absence rather than trusting it: the feature's only
     * route is a GET, and no review route takes a camera field.
     */
    public function test_no_endpoint_accepts_a_camera(): void
    {
        $symbol = $this->symbol('Wall Mount', [
            ['key' => 'det_a', 'bbox' => [100, 100, 12, 12], 'page' => 1, 'status' => 'approved', 'confidence' => 0.9],
        ]);

        // A move carrying camera state alongside the bbox: the extra fields are
        // not validated, not read, and not stored.
        $this->actingAs($this->user)
            ->post(route('reviews.occurrenceMove', [$this->result, $symbol, 'det_a']), [
                'bbox' => [400, 300, 12, 12],
                'zoom' => 3.5,
                'rotation' => 42,
                'tilt' => 55,
                'panX' => 120,
            ])
            ->assertSessionHasNoErrors();

        $occurrence = $symbol->fresh()->occurrences[0];

        $this->assertSame([400.0, 300.0, 12.0, 12.0], array_map('floatval', $occurrence['bbox']));
        foreach (['zoom', 'rotation', 'tilt', 'panX'] as $field) {
            $this->assertArrayNotHasKey($field, $occurrence);
        }
    }

    /** Pages can differ in size and orientation; each carries its own. */
    public function test_pages_may_have_different_sizes_and_orientations(): void
    {
        $this->result->update([
            'page_sizes' => [
                1 => ['width' => 2550, 'height' => 3300],
                // Landscape, and a different size entirely.
                2 => ['width' => 3300, 'height' => 2550],
            ],
        ]);

        $this->actingAs($this->user)
            ->get(route('reviews.threeD', $this->result))
            ->assertInertia(function (Assert $page) {
                $dimensions = $page->toArray()['props']['pageDimensions'];

                $this->assertGreaterThan($dimensions[1]['width'], $dimensions[1]['height']);
                $this->assertGreaterThan($dimensions[2]['height'], $dimensions[2]['width']);
            });
    }

    /** The estimate reads the review, and the viewer's edits land in the review. */
    public function test_an_edit_here_reaches_the_estimate_data(): void
    {
        $symbol = $this->symbol('Wall Mount', [
            ['key' => 'det_a', 'bbox' => [1, 1, 4, 4], 'page' => 1, 'status' => 'approved', 'confidence' => 1],
        ]);

        $before = $this->result->fresh()->reviewTally()['approvedCount'];

        $this->actingAs($this->user)
            ->post(route('reviews.count', [$this->result, $symbol]), ['step' => 4])
            ->assertSessionHasNoErrors();

        $this->assertSame($before + 4, $this->result->fresh()->reviewTally()['approvedCount']);
    }
}
