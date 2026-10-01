<?php

namespace App\Support;

use App\Models\Project;

/**
 * Which screen a drawing's details were opened from, so Back can undo the
 * step that was actually taken.
 *
 * A drawing is reached from its own project and from the takeoff history —
 * Back has to mean the one it was actually reached from, not always the same
 * screen regardless of how someone got here.
 *
 * The origin travels as a name, never a URL, matching `App\Support\JobOrigin`:
 * a URL in the query string would let any link anywhere decide where a button
 * on this page points, including off this site entirely. A name is matched
 * against the list below or discarded.
 */
final class DrawingOrigin
{
    /** @var array<string, array{0: string, 1: string}> name => [button label, route] */
    private const ORIGINS = [
        'history' => ['Back', 'takeoffs.index'],
    ];

    /** The origin as given, once it is one this app actually serves. */
    public static function name(?string $from): ?string
    {
        return $from !== null && array_key_exists($from, self::ORIGINS) ? $from : null;
    }

    /**
     * Where Back goes, and what the button says.
     *
     * A drawing belongs to exactly one project, so — unlike a job, which has
     * no single home screen — the honest default when nothing else was said
     * is the project itself, not a module-wide list.
     *
     * @return array{label: string, url: string}
     */
    public static function back(Project $project, ?string $from): array
    {
        $origin = self::name($from);

        if ($origin === null) {
            return ['label' => 'Back to project', 'url' => route('projects.show', $project)];
        }

        [$label, $route] = self::ORIGINS[$origin];

        return ['label' => $label, 'url' => route($route)];
    }
}
