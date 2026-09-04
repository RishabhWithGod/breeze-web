<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A crew: a supervisor and the foremen who work under them.
 *
 * The register is organised by team because that is how work is actually
 * staffed — "who has room" is a question about a crew before it is a question
 * about a person.
 *
 * A team is not required. Everyone on the register before teams existed has
 * none, and someone hired before their crew is decided has none either; the
 * list shows them together rather than hiding them.
 */
class Team extends Model
{
    /** A team is its name. What it covers shows in the work it carries. */
    protected $fillable = ['name'];

    /** Everyone on the crew who runs work. */
    public function foremen(): HasMany
    {
        return $this->hasMany(Foreman::class)
            ->where('role', Foreman::ROLE_FOREMAN)
            ->orderBy('name');
    }

    /** Everyone on the crew who supervises it. */
    public function supervisors(): HasMany
    {
        return $this->hasMany(Foreman::class)
            ->where('role', Foreman::ROLE_SUPERVISOR)
            ->orderBy('name');
    }

    /** @return HasMany<Foreman, $this> */
    public function members(): HasMany
    {
        // Supervisors first, then foremen, each alphabetically — the order the
        // crew is actually read in.
        return $this->hasMany(Foreman::class)
            ->orderByRaw("field(role, '".Foreman::ROLE_SUPERVISOR."') desc")
            ->orderBy('name');
    }
}
