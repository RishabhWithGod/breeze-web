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
            'seats' => ['used' => $seatsUsed, 'limit' => $plan['max_users']],
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
        $limit = $this->plan(Subscription::forUser($user)->plan)['max_users'];

        return $limit === null || $this->seatsUsed($user) < $limit;
    }

    /**
     * Whether the company could move onto `$planKey` today — refused while it
     * has more users, projects or storage than that plan holds.
     *
     * @return list<string> what stands in the way; empty when nothing does
     */
    public function blockersFor(string $planKey, ?User $user = null): array
    {
        $limits = $this->plan($planKey)['limits'];
        $blockers = [];

        $maxUsers = $this->plan($planKey)['max_users'];
        if ($maxUsers !== null && $this->seatsUsed($user) > $maxUsers) {
            $blockers[] = "{$this->seatsUsed($user)} people have an account, and this plan has {$maxUsers}";
        }

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
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            // A company's plan counts that company's people, not everyone's.
            ->when(
                $user?->company_id !== null,
                fn ($query) => $query->where('company_id', $user->company_id),
            )
            ->count();
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
