<?php

namespace App\Models\Concerns;

use App\Models\CompanyProfile;
use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row that belongs to one company: read only by that company's people, and
 * created under the company of whoever creates it.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model): void {
            if ($model->company_id === null) {
                $model->company_id = CompanyScope::signedIn()?->company_id;
            }
        });
    }

    /** @return BelongsTo<CompanyProfile, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(CompanyProfile::class, 'company_id');
    }
}
