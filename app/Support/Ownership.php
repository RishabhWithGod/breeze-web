<?php

namespace App\Support;

use App\Models\CompanyProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whose work a person may see and change.
 *
 * A company can have several managers, and each sees everything the company's
 * managers have made — clients, projects, estimates, jobs, invoices. An account
 * with no company (from before there were companies) keeps the old rule: its
 * own work, and nobody else's.
 */
class Ownership
{
    /**
     * The people whose work `$user` may act on: everyone in their company, or
     * only themselves when they have none. For `whereIn('user_id', ...)`.
     *
     * @return Builder<User>|list<int>
     */
    public static function userIds(User $user): Builder|array
    {
        if ($user->company_id === null) {
            return [$user->id];
        }

        return User::query()->where('company_id', $user->company_id)->select('id');
    }

    /**
     * The same people as a plain list of ids — for validation rules, which cannot
     * take a query.
     *
     * @return list<int>
     */
    public static function userIdList(User $user): array
    {
        $ids = self::userIds($user);

        return is_array($ids) ? $ids : $ids->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Everyone `$user` works alongside — the crew a manager reads the time of.
     * Unlike {@see userIds()}, an account with no company works alongside the
     * others that have none, as it always did.
     *
     * @return Builder<User>
     */
    public static function peopleIds(User $user): Builder
    {
        $people = User::query()->select('id');

        return $user->company_id === null
            ? $people->whereNull('company_id')
            : $people->where('company_id', $user->company_id);
    }

    /** Whether something made by `$ownerId` is `$user`'s to act on. */
    public static function owns(User $user, ?int $ownerId): bool
    {
        if ($ownerId === null) {
            return false;
        }

        if ($ownerId === $user->id) {
            return true;
        }

        return $user->company_id !== null
            && User::query()->whereKey($ownerId)->where('company_id', $user->company_id)->exists();
    }

    /** Whether `$otherUserId` works alongside `$user` — the same company (or both none). */
    public static function worksWith(User $user, ?int $otherUserId): bool
    {
        return $otherUserId !== null
            && ($otherUserId === $user->id || self::peopleIds($user)->whereKey($otherUserId)->exists());
    }

    /**
     * Whose price book a person works from: their company's (kept under the
     * account that set the company up), so every manager prices from the same
     * book. An account with no company keeps its own.
     */
    public static function bookOwnerId(?int $userId): ?int
    {
        if ($userId === null) {
            return null;
        }

        $companyId = User::query()->whereKey($userId)->value('company_id');

        return $companyId === null
            ? $userId
            : (int) (CompanyProfile::query()->whereKey($companyId)->value('user_id') ?? $userId);
    }
}
