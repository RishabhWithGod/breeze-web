<?php

namespace App\Models\Relations;

use App\Support\Ownership;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person's work, widened to their company's.
 *
 * Reading through it gives everything the company's managers have made, not just
 * this person's; creating through it still records this person as the maker.
 *
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends HasMany<TRelatedModel, TDeclaringModel>
 */
class CompanyHasMany extends HasMany
{
    public function addConstraints(): void
    {
        if (static::$constraints) {
            $this->query->whereIn($this->foreignKey, Ownership::userIds($this->parent));
        }
    }
}
