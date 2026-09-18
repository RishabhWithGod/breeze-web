<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A crew: a foreman, the journeymen who work under them, and the apprentices
 * learning under those.
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

    /** Everyone on the crew who oversees it. */
    public function foremen(): HasMany
    {
        return $this->hasMany(Foreman::class)
            ->where('role', Foreman::ROLE_FOREMAN)
            ->orderBy('name');
    }

    /** Everyone on the crew who runs their own tasks. */
    public function journeymen(): HasMany
    {
        return $this->hasMany(Foreman::class)
            ->where('role', Foreman::ROLE_JOURNEYMAN)
            ->orderBy('name');
    }

    /** Everyone on the crew still learning under supervision. */
    public function apprentices(): HasMany
    {
        return $this->hasMany(Foreman::class)
            ->where('role', Foreman::ROLE_APPRENTICE)
            ->orderBy('name');
    }

    /** Everyone on the crew who can run a task themselves — journeymen and apprentices. */
    public function workers(): HasMany
    {
        return $this->hasMany(Foreman::class)
            ->whereIn('role', Foreman::WORKER_ROLES)
            ->orderBy('name');
    }

    /** @return HasMany<Foreman, $this> */
    public function members(): HasMany
    {
        // Foremen first, then journeymen, then apprentices, each
        // alphabetically — the order the crew is actually read in.
        return $this->hasMany(Foreman::class)
            ->orderByRaw("field(role, '".implode("', '", Foreman::ROLES)."')")
            ->orderBy('name');
    }
}
