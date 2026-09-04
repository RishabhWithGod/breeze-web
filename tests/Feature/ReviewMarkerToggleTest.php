<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Project;
use App\Models\SymbolReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rejecting a symbol from its marker on the drawing.
 *
 * The marker's own toggle is an in-place action: the box under the pointer
 * turns red and nothing else on the screen moves. It asks for every prop except
 * the flash, because the review screen renders that flash as a banner above the
 * drawing — so the first rejection of a session used to make the banner appear
 * and push the whole page down, which reads as a reload. Every rejection after
 * it only changed the banner's text, which is why it looked like a
 * first-time-only fault.
 *
 * The banner is still right for the card grid's own buttons, where the row can
 * be scrolled out of sight. This pins the difference.
 */
class ReviewMarkerToggleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AiResult $result;

    private SymbolReview $review;

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
        ]);
        $this->review = $this->result->reviews()->create([
            'project_id' => $project->id,
            'name' => 'Wall Mount',
            // The engine's own label for it, kept beside the reviewer's.
            'ai_name' => 'Wall Mount',
            'ai_count' => 3,
            'final_count' => 3,
            'status' => 'approved',
        ]);
    }

    /** The rejection itself is unchanged — only what the screen asks back for. */
    public function test_rejecting_from_the_marker_still_rejects(): void
    {
        $this->actingAs($this->user)
            ->post(route('reviews.reject', [$this->result, $this->review]))
            ->assertRedirect();

        $this->assertSame('rejected', $this->review->fresh()->status);
    }

    /**
     * The banner the card grid relies on is still flashed — this route is
     * shared, and nothing about it changed.
     */
    public function test_the_route_still_flashes_for_the_card_grid(): void
    {
        $this->actingAs($this->user)
            ->post(route('reviews.reject', [$this->result, $this->review]))
            ->assertSessionHas('warning');
    }

    /**
     * And a visit that asks for everything except the flash gets everything
     * except the flash — which is what stops the banner appearing under the
     * marker's toggle, and with it the shove that looked like a reload.
     */
    public function test_a_visit_excluding_the_flash_is_served_without_it(): void
    {
        $this->actingAs($this->user);

        /*
         * The version is read out of the page the app itself just rendered,
         * rather than guessed. A version the middleware disagrees with earns a
         * 409 telling the client to reload — not the page — and a hardcoded one
         * would fail every time the assets are rebuilt.
         */
        $page = $this->get(route('reviews.show', $this->result))->assertOk();
        $rendered = json_decode(html_entity_decode(
            (string) preg_replace('/.*data-page="([^"]*)".*/s', '$1', $page->getContent()),
        ), true);

        $this->assertArrayHasKey('flash', $rendered['props']);

        $withoutFlash = $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $rendered['version'],
            'X-Inertia-Partial-Component' => 'AiReview',
            'X-Inertia-Partial-Except' => 'flash',
        ])->get(route('reviews.show', $this->result))->assertOk();

        $props = $withoutFlash->json('props');

        $this->assertArrayNotHasKey('flash', $props);
        // Everything the drawing redraws from is still there.
        $this->assertArrayHasKey('symbols', $props);
        $this->assertArrayHasKey('tally', $props);
        $this->assertArrayHasKey('distinctNames', $props);
    }
}
