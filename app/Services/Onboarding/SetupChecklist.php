<?php

namespace App\Services\Onboarding;

use App\Models\CompanyProfile;
use App\Models\Foreman;
use App\Models\PriceBookItem;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Company\ManagerRegistrar;
use App\Support\Ownership;

/**
 * How far a company has got through first-time setup.
 *
 * Nothing is ticked by hand: each step is done when the thing it asks for exists
 * — a company on file, someone on the team, a price list, a client, a project — so
 * the progress is always what is true, and it follows the work wherever it is done.
 * Two steps (the team and the price list) may be skipped; the others may not, and
 * the project waits for its client.
 */
class SetupChecklist
{
    public const COMPANY = 'company';

    public const TEAM = 'team';

    public const COMMODITIES = 'commodities';

    public const CLIENT = 'client';

    public const PROJECT = 'project';

    /** Steps a company may leave for later. */
    public const SKIPPABLE = [self::TEAM, self::COMMODITIES];

    /** Steps that have to be done before setup can be called finished. */
    public const REQUIRED = [self::COMPANY, self::CLIENT, self::PROJECT];

    /**
     * @return array{
     *     steps: list<array<string, mixed>>, completed: int, total: int, percent: int,
     *     next: ?string, finished: bool, canFinish: bool
     * }
     */
    public function for(User $user): array
    {
        $company = $user->company_id === null ? null : CompanyProfile::query()->find($user->company_id);
        $skipped = $company?->onboarding_skipped ?? [];

        $done = [
            self::COMPANY => $company !== null,
            self::TEAM => $this->teamStarted($user),
            self::COMMODITIES => $this->hasOwnPriceList($user),
            self::CLIENT => $user->clients()->exists(),
            self::PROJECT => $user->projects()->exists(),
        ];

        $definitions = [
            self::COMPANY => ['Company Profile', 'Add your company details, logo, and preferences.', '/settings?tab=company', 'Review'],
            self::TEAM => ['Invite Team', 'Add team members and set their roles.', '/team-setup', 'Invite team'],
            self::COMMODITIES => ['Configure Commodity List', 'Set up your commodity categories and items.', '/commodities', 'Open commodity list'],
            self::CLIENT => ['Create First Client', 'Add your first client to get started.', '/clients/create', 'Add client'],
            self::PROJECT => ['Create First Project', 'Set up your first project and start building estimates.', '/projects/create', 'Add project'],
        ];

        $steps = [];
        foreach ($definitions as $key => [$title, $description, $href, $action]) {
            $locked = $key === self::PROJECT && ! $done[self::CLIENT];

            $status = match (true) {
                $done[$key] => 'completed',
                $locked => 'locked',
                in_array($key, $skipped, true) => 'skipped',
                default => 'pending',
            };

            $steps[] = [
                'key' => $key,
                'title' => $title,
                'description' => $description,
                'status' => $status,
                'href' => $href,
                'action' => $action,
                'skippable' => in_array($key, self::SKIPPABLE, true),
                'lockedBecause' => $locked ? 'Add your first client first.' : null,
            ];
        }

        $completed = count(array_filter($done));
        $next = collect($steps)->firstWhere('status', 'pending')['key'] ?? null;

        return [
            'steps' => $steps,
            'completed' => $completed,
            'total' => count($steps),
            'percent' => (int) round($completed / count($steps) * 100),
            'next' => $next,
            'finished' => $company?->onboarding_finished_at !== null,
            'canFinish' => collect(self::REQUIRED)->every(fn (string $key) => $done[$key]),
        ];
    }

    /**
     * Whether `$user` is held on the checklist instead of the dashboard: a manager of a
     * company whose setup is neither finished nor complete.
     */
    public function holds(User $user): bool
    {
        return $user->company_id !== null
            && app(ManagerRegistrar::class)->isManager($user)
            && $this->pending($user) !== null;
    }

    /**
     * What the Get Started screen is given.
     *
     * @return array{checklist: array<string, mixed>, help: array<string, string>}
     */
    public function page(User $user): array
    {
        return [
            'checklist' => $this->for($user),
            'help' => collect(config('onboarding.help_links'))->filter()->all(),
        ];
    }

    /** Whether the checklist is still worth showing on the dashboard. */
    public function pending(User $user): ?array
    {
        $checklist = $this->for($user);

        return $checklist['finished'] || $checklist['completed'] === $checklist['total']
            ? null
            : ['completed' => $checklist['completed'], 'total' => $checklist['total'], 'next' => $checklist['next']];
    }

    /** The team is started: a crew member on the register, another login, or an invitation out. */
    private function teamStarted(User $user): bool
    {
        return Foreman::query()->exists()
            || User::query()->where('company_id', $user->company_id)->whereKeyNot($user->id)->exists()
            || TeamInvitation::query()->whereIn('status', [TeamInvitation::STATUS_PENDING, TeamInvitation::STATUS_ACCEPTED])->exists();
    }

    /** The company has a price list of its own — its commodity list — not just the shared one everyone falls back to. */
    private function hasOwnPriceList(User $user): bool
    {
        $owner = Ownership::bookOwnerId($user->id);

        return $owner !== null && PriceBookItem::query()->active()->where('user_id', $owner)->exists();
    }
}
