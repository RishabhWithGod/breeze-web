<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The notification list: search, and the Approvals tab for what is waiting on a
 * decision.
 */
class NotificationListTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function note(string $type, string $title, string $detail = 'Detail', bool $read = false, ?User $for = null): AppNotification
    {
        return AppNotification::create([
            'user_id' => ($for ?? $this->user)->id,
            'type' => $type,
            'title' => $title,
            'detail' => $detail,
            'read_at' => $read ? now() : null,
        ]);
    }

    private function list(string $query = ''): TestResponse
    {
        return $this->actingAs($this->user)->get('/notifications'.$query);
    }

    public function test_search_matches_the_title_or_the_detail(): void
    {
        $this->note('takeoff-ready', 'AI Takeoff Completed', 'The takeoff for "Coldbear Restaurant" is ready.');
        $this->note('estimate-ready', 'Estimate EST-1004 created', 'Down Hall');
        $this->note('job-started', 'Job started', 'Riverside Office');

        $this->list('?search=coldbear')->assertInertia(fn ($page) => $page
            ->has('notifications.data', 1)
            ->where('notifications.data.0.title', 'AI Takeoff Completed')
            ->where('filters.search', 'coldbear'));

        $this->list('?search=EST-1004')->assertInertia(fn ($page) => $page->has('notifications.data', 1));

        $this->list('?search=nothing-like-this')->assertInertia(fn ($page) => $page->has('notifications.data', 0));
    }

    public function test_the_tab_counts_follow_the_search_too(): void
    {
        $this->note('takeoff-ready', 'AI Takeoff Completed', 'Coldbear');
        $this->note('job-started', 'Job started', 'Riverside');

        $this->list('?search=coldbear')->assertInertia(fn ($page) => $page
            ->where('tabCounts.all', 1)
            ->where('tabCounts.unread', 1));
    }

    public function test_the_approvals_tab_holds_only_what_is_waiting_on_a_decision(): void
    {
        $this->note('technician-pending', 'New technician signup');
        $this->note('job-ready-for-review', 'Job ready for review');
        $this->note('time-entry-submitted', 'Time submitted');
        $this->note('takeoff-ready', 'AI Takeoff Completed');
        $this->note('invoice-paid', 'Invoice paid');

        $this->list('?tab=approvals')->assertInertia(fn ($page) => $page
            ->has('notifications.data', 3)
            ->where('tabCounts.approvals', 3)
            ->where('tabCounts.all', 5)
            ->where('filters.tab', 'approvals'));
    }

    public function test_an_approval_already_read_no_longer_counts_as_waiting(): void
    {
        $this->note('technician-pending', 'Waiting');
        $this->note('technician-pending', 'Dealt with', 'Detail', read: true);

        $this->list()->assertInertia(fn ($page) => $page->where('tabCounts.approvals', 1));

        // The tab itself still lists both — it is the history of asks, not only the open ones.
        $this->list('?tab=approvals')->assertInertia(fn ($page) => $page->has('notifications.data', 2));
    }

    public function test_only_the_signed_in_users_notifications_are_listed(): void
    {
        $other = User::factory()->create();
        $this->note('technician-pending', 'Mine');
        $this->note('technician-pending', 'Theirs', for: $other);

        $this->list('?tab=approvals')->assertInertia(fn ($page) => $page
            ->has('notifications.data', 1)
            ->where('notifications.data.0.title', 'Mine'));
    }
}
