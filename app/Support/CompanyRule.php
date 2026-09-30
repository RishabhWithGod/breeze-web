<?php

namespace App\Support;

use App\Models\Scopes\CompanyScope;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Validation rules that only see the signed-in person's own company.
 *
 * `exists:teams,id` asks the database directly, past the model scope that hides
 * other companies' rows, so a form could name another company's team and be
 * believed. These ask the same question inside the company.
 */
class CompanyRule
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)->where(self::sameCompany());
    }

    public static function unique(string $table, string $column): Unique
    {
        return Rule::unique($table, $column)->where(self::sameCompany());
    }

    private static function sameCompany(): Closure
    {
        $company = CompanyScope::signedIn()?->company_id;

        return fn (Builder $query) => $company === null
            ? $query->whereNull('company_id')
            : $query->where('company_id', $company);
    }
}
