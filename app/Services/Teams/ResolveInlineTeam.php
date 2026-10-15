<?php

namespace App\Services\Teams;

use App\Models\Team;

/**
 * Turns a `new:<name>` draft from an inline Team picker into a real team.
 *
 * The picker used to create the team the moment someone clicked "Add team",
 * which meant abandoning the form around it — never saving the client or job
 * that was supposedly the reason for the new crew — still left the team
 * behind in the register and in everyone else's dropdown. Creating it here
 * instead, as part of validating the record that actually wants it, means a
 * team drafted on a form nobody finishes is never written at all.
 */
class ResolveInlineTeam
{
    private const PREFIX = 'new:';

    /** Leaves a real id, blank value, or null alone; creates-or-reuses a team for a draft. */
    public function __invoke(?string $teamId): ?string
    {
        if ($teamId === null || ! str_starts_with($teamId, self::PREFIX)) {
            return $teamId;
        }

        $name = trim(substr($teamId, strlen(self::PREFIX)));

        // Nothing worth creating — let the normal "must be an integer" rule
        // reject it rather than silently dropping the field.
        if ($name === '') {
            return $teamId;
        }

        return (string) Team::firstOrCreate(['name' => $name])->id;
    }
}
