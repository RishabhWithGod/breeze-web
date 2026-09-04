<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Dates, written the way the United States writes them.
 *
 * MM/DD/YYYY wherever a date is read as a value — a table cell, a due date, a
 * shift's day, a line on an exported invoice. A month heading over a calendar
 * grid keeps its name, because "September 2026" has no day in it and there is
 * no MM/DD/YYYY for a month.
 */
class UsDateFormatTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'Project Manager']);
    }

    /** Every day on the calendar names itself numerically, month first. */
    public function test_a_calendar_day_is_written_month_first(): void
    {
        $this->actingAs($this->user)
            ->get('/scheduling/calendar?view=week&date=2026-03-04')
            ->assertInertia(function (Assert $page) {
                $labels = array_column($page->toArray()['props']['days'], 'label');

                // 03/04, not "Mar 4" and not 04/03 — the fourth of March.
                $this->assertContains('Wed, 03/04', $labels);
            });
    }

    /**
     * The week being looked at, both ends written out.
     *
     * "Mar 1 – 7, 2026" read as a date to anyone scanning it, and read wrong to
     * half of them.
     */
    public function test_the_week_heading_is_written_month_first(): void
    {
        $this->actingAs($this->user)
            ->get('/scheduling/calendar?view=week&date=2026-03-04')
            ->assertInertia(fn (Assert $page) => $page
                ->where('periodLabel', '03/01/2026 – 03/07/2026'));
    }

    /**
     * A month heading is not a date.
     *
     * It has no day in it, so it keeps its name rather than being forced into a
     * shape it does not fit.
     */
    public function test_a_month_heading_keeps_its_name(): void
    {
        $this->actingAs($this->user)
            ->get('/scheduling/calendar?view=month&date=2026-03-04')
            ->assertInertia(fn (Assert $page) => $page
                ->where('periodLabel', 'March 2026'));
    }
}
