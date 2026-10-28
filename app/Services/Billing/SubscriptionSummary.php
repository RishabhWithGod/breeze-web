<?php

namespace App\Services\Billing;

use App\Models\AiResult;
use App\Models\Estimate;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\Upload;
use App\Models\User;

/**
 * A company's plan, and how much of it is being used.
 *
 * Limits come from `config/subscription.php`; the figures are counted from the real tables — nothing is typed
 * in. Takeoffs and estimates count for the cycle running now; projects and
 * storage are what is on file.
 */
class SubscriptionSummary
{
    private const GIGABYTE = 1024 * 1024 * 1024;

    /** @return array<string, mixed> */
    public function build(?User $user = null): array
    {
        $subscription = Subscription::forUser($user);
        $plan = $this->plan($subscription->plan);
        $since = $subscription->cycleStart();

        $seatsUsed = $this->seatsUsed($user);
        $storageBytes = (int) Upload::query()->sum('size_bytes');

        return [
            'plan' => ['key' => $subscription->plan] + $this->publicPlan($plan),
            'status' => $subscription->status,
            'billingCycle' => $subscription->billing_cycle,
            'renewsOn' => $subscription->renews_on->toDateString(),
            // The seats this company actually bought, not the plan's own
            // suggested starting count — see `Subscription::seats`.
            'seats' => ['used' => $seatsUsed, 'limit' => $subscription->seats, 'byRole' => $this->seatsUsedByRole($user)],
            'annualDiscountPercent' => (int) config('subscription.annual_discount_percent'),
            'usage' => [
                [
                    'key' => 'ai_takeoffs',
                    'label' => 'AI Takeoffs',
                    'used' => AiResult::query()->where('created_at', '>=', $since)->count(),
                    'limit' => $plan['limits']['ai_takeoffs'],
                    'unit' => null,
                ],
                [
                    'key' => 'estimates',
                    'label' => 'Estimates',
                    'used' => Estimate::query()->where('created_at', '>=', $since)->count(),
                    'limit' => $plan['limits']['estimates'],
                    'unit' => null,
                ],
                [
                    'key' => 'projects',
                    'label' => 'Projects',
                    'used' => Project::query()->count(),
                    'limit' => $plan['limits']['projects'],
                    'unit' => null,
                ],
                [
                    'key' => 'storage',
                    'label' => 'Storage',
                    'used' => round($storageBytes / self::GIGABYTE, 2),
                    'limit' => $plan['limits']['storage_gb'],
                    'unit' => 'GB',
                ],
            ],
            'plans' => collect(config('subscription.plans'))
                ->map(fn (array $plan, string $key) => ['key' => $key] + $this->publicPlan($plan))
                ->values()
                ->all(),
        ];
    }

    /** Whether one more person can hold an account on this person's plan today. */
    public function hasSeatAvailable(?User $user): bool
    {
        // A seat promised to someone invited is as taken as one in use: approving or adding
        // another person into it would leave the invitee with a link that no longer works.
        $held = $user?->company_id === null ? 0 : \App\Models\TeamInvitation::withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->where('status', \App\Models\TeamInvitation::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->count();

        return $this->seatsUsed($user) + $held < Subscription::forUser($user)->seats;
    }

    /**
     * Whether the company could move onto `$planKey` today — refused while it
     * has more projects or storage than that plan holds.
     *
     * Seats are not part of this: a plan switch changes the per-seat rate and
     * feature set, not how many seats were bought, so the seat count carries
     * over unchanged and there is nothing about it to block here.
     *
     * @return list<string> what stands in the way; empty when nothing does
     */
    public function blockersFor(string $planKey, ?User $user = null): array
    {
        $limits = $this->plan($planKey)['limits'];
        $blockers = [];

        $projects = Project::query()->count();
        if ($projects > $limits['projects']) {
            $blockers[] = "{$projects} projects are on file, and this plan has {$limits['projects']}";
        }

        $storage = round((int) Upload::query()->sum('size_bytes') / self::GIGABYTE, 2);
        if ($storage > $limits['storage_gb']) {
            $blockers[] = "{$storage} GB of drawings are on file, and this plan has {$limits['storage_gb']} GB";
        }

        return $blockers;
    }

    /** Everyone who can sign in — the seats taken. */
    private function seatsUsed(?User $user = null): int
    {
        return $this->companyUsers($user)->count();
    }

    /**
     * The same seats taken, broken down by role — what "Invite Team Members"
     * shows before someone tries to add another person and only then finds
     * out there is no room left.
     *
     * @return list<array{role: string, count: int}>
     */
    private function seatsUsedByRole(?User $user = null): array
    {
        return $this->companyUsers($user)
            ->selectRaw('role, count(*) as count')
            ->groupBy('role')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => ['role' => $row->role ?? 'Unassigned', 'count' => (int) $row->count])
            ->values()
            ->all();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<User> */
    private function companyUsers(?User $user = null)
    {
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            // A company's plan counts that company's people, not everyone's.
            ->when(
                $user?->company_id !== null,
                fn ($query) => $query->where('company_id', $user->company_id),
            );
    }

    /** @return array<string, mixed> */
    private function plan(string $key): array
    {
        return config("subscription.plans.{$key}") ?? config('subscription.plans.'.config('subscription.default_plan'));
    }

    /** A plan as the screens read it: camelCase, and the volume tiers spelled out. */
    private function publicPlan(array $plan): array
    {
        return [
            'name' => $plan['name'],
            'tagline' => $plan['tagline'],
            'description' => $plan['description'],
            'price' => $plan['price'],
            'maxUsers' => $plan['max_users'],
            'usersLabel' => $plan['users_label'],
            'popular' => $plan['popular'],
            'limits' => $plan['limits'],
            'highlights' => $plan['highlights'],
            'features' => $plan['features'],
        ];
    }
}
