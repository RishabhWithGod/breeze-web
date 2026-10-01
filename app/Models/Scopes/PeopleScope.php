<?php

namespace App\Models\Scopes;

use App\Support\Ownership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Keeps a record made by a person (their time, their check-ins) inside their
 * own company: whoever is signed in sees only what people of their company made.
 * Outside a request nothing is narrowed.
 */
class PeopleScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = CompanyScope::signedIn();

        if ($user === null) {
            return;
        }

        $builder->whereIn($model->qualifyColumn('user_id'), Ownership::peopleIds($user));
    }
}
