<?php

namespace App\Models\Scopes;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Shows a signed-in person only their own company's rows.
 *
 * An account with no company (one that existed before companies did) sees the
 * rows that have none, so nothing that already existed disappears. Outside a
 * request — queues, commands — there is nobody signed in, and nothing is narrowed.
 */
class CompanyScope implements Scope
{
    /**
     * Whoever is signed in, if the request has already worked that out.
     *
     * `hasUser()` rather than `user()`: asking for the user would make the guard
     * go and authenticate, and an early answer is cached for the rest of the
     * request. This only reads what authentication has already settled.
     */
    public static function signedIn(): ?Authenticatable
    {
        return Auth::hasUser() ? Auth::user() : null;
    }

    public function apply(Builder $builder, Model $model): void
    {
        $user = self::signedIn();

        if ($user === null) {
            return;
        }

        $column = $model->qualifyColumn('company_id');

        $user->company_id === null
            ? $builder->whereNull($column)
            : $builder->where($column, $user->company_id);
    }
}
