<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\Team;
use App\Models\User;
use App\Services\Scheduling\CrewBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The crew calendar is drawn from the jobs themselves — a crew and a date range
 * put a job on it; nothing is booked.
 */
class CrewBoardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /** A job with one task on it — which is what puts it on the calendar. */
    private function job(array $attributes = [], bool $withTask = true): Job
    {
        $job = Job::create([
            'user_id' => $this->user->id,
            'name' => 'Job',
            'client' => 'Client',
            'status' => 'scheduled',
            'priority' => 'medium',
            ...$attributes,
        ]);

        if ($withTask) {
            $schedule = JobSchedule::create(['job_id' => $job->id, 'working_days' => [1, 2, 3, 4, 5]]);
            $job->tasks()->create(['job_schedule_id' => $schedule->id, 'title' => 'Task', 'position' => 0]);
        }

        return $job;
    }

    private function board(string $view = 'week', string $date = '2026-09-30'): array
    {
        return app(CrewBoard::class)->build($this->user, $view, Carbon::parse($date));
    }

    public function test_a_week_runs_monday_to_sunday(): void
    {
        $board = $this->board();

        $this->assertSame('2026-09-28', $board['from']);
        $this->assertSame('2026-10-04', $board['to']);
        $this->assertCount(7, $board['days']);
        $this->assertSame('Mon', $board['days'][0]['weekday']);
    }

    public function test_a_job_appears_on_its_crew_on_working_days_only(): void
    {
        $team = Team::create(['name' => 'Team A']);
        // Fri Oct 2 → Mon Oct 5: the weekend between is not worked.
        $this->job(['team_id' => $team->id, 'start_date' => '2026-10-02', 'end_date' => '2026-10-05']);

        $dates = array_column($this->board()['blocks'], 'date');

        $this->assertSame(['2026-10-02'], $dates);
        $this->assertSame(['2026-10-05'], array_column($this->board('week', '2026-10-06')['blocks'], 'date'));
    }

    public function test_the_window_is_not_moved_by_drawing_a_job_that_starts_before_it(): void
    {
        $team = Team::create(['name' => 'Team A']);
        $this->job(['team_id' => $team->id, 'start_date' => '2026-09-01', 'end_date' => '2026-10-30']);

        $board = $this->board();

        $this->assertSame('2026-09-28', $board['from']);
        $this->assertSame('2026-10-04', $board['to']);
        $this->assertCount(5, $board['blocks']);
    }

    public function test_two_jobs_on_one_crew_the_same_day_are_a_conflict(): void
    {
        $team = Team::create(['name' => 'Team A']);
        $this->job(['team_id' => $team->id, 'name' => 'One', 'start_date' => '2026-09-30', 'end_date' => '2026-09-30']);
        $this->job(['team_id' => $team->id, 'name' => 'Two', 'start_date' => '2026-09-30', 'end_date' => '2026-09-30']);

        $states = array_column($this->board()['blocks'], 'state');

        $this->assertSame(['conflict', 'conflict'], $states);
    }

    public function test_completed_and_delayed_jobs_read_as_such(): void
    {
        $team = Team::create(['name' => 'Team A']);
        $this->job(['team_id' => $team->id, 'status' => 'completed', 'start_date' => '2026-09-29', 'end_date' => '2026-09-29']);
        $this->job(['team_id' => $team->id, 'status' => 'delayed', 'start_date' => '2026-09-30', 'end_date' => '2026-09-30']);
        $this->job(['team_id' => $team->id, 'status' => 'in-progress', 'start_date' => '2026-10-01', 'end_date' => '2026-10-01']);

        $this->assertSame(
            ['completed', 'attention', 'in-progress'],
            array_column($this->board()['blocks'], 'state'),
        );
    }

    public function test_a_job_with_no_tasks_is_listed_rather_than_drawn(): void
    {
        $team = Team::create(['name' => 'Team A']);
        // Even with a crew and dates, a job nobody has broken into tasks is unassigned.
        $this->job(['name' => 'Waiting', 'team_id' => $team->id, 'start_date' => '2026-09-30', 'end_date' => '2026-09-30'], false);

        $board = $this->board();

        $this->assertSame([], $board['blocks']);
        $this->assertSame(1, $board['unassignedTotal']);
        $this->assertSame('Waiting', $board['unassigned'][0]['name']);
    }

    public function test_a_job_with_tasks_but_no_crew_is_drawn_on_a_no_crew_row(): void
    {
        $this->job(['start_date' => '2026-09-30', 'end_date' => '2026-09-30']);

        $board = $this->board();

        $this->assertSame(0, $board['blocks'][0]['crewId']);
        $this->assertSame('No crew', end($board['crews'])['name']);
        $this->assertSame(0, $board['unassignedTotal']);
    }

    public function test_the_calendar_screen_carries_the_board(): void
    {
        $team = Team::create(['name' => 'Team A']);
        $this->job(['team_id' => $team->id, 'start_date' => '2026-09-30', 'end_date' => '2026-09-30']);

        $this->actingAs($this->user)
            ->get('/scheduling/calendar?view=day&date=2026-09-30')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Scheduling')
                ->where('board.view', 'day')
                ->has('board.blocks', 1)
                ->where('board.crews.0.name', 'Team A'));
    }
}
