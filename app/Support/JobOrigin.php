<?php

namespace App\Support;

/**
 * Which screen a job was opened from, so Back can undo the step that was taken.
 *
 * A job is reached from the jobs list, from the scheduling screens and from the
 * task list — and from a job you can go deeper still, into its tasks. Back has
 * to walk that path in reverse: coming out of a task should land on the job, and
 * coming out of the job should land wherever the trail began.
 *
 * The origin travels as a name, never a URL. A URL in the query string would let
 * any link anywhere decide where a button on our page points, including off this
 * site entirely. A name is matched against the list below or discarded.
 *
 * One list, used by every controller on the path — JobController and
 * JobTaskSetupController both read it, so the two can never disagree about
 * where "back to scheduling" goes.
 */
final class JobOrigin
{
    /** @var array<string, array{0: string, 1: string}> name => [button label, route] */
    private const ORIGINS = [
        'scheduling' => ['Back to scheduling', 'scheduling.index'],
        'scheduling-calendar' => ['Back to calendar', 'scheduling.calendar'],
        'scheduling-availability' => ['Back to availability', 'scheduling.availability'],
        'tasks' => ['Back to tasks', 'tasks.index'],
    ];

    /** The origin as given, once it is one this app actually serves. */
    public static function name(?string $from): ?string
    {
        return $from !== null && array_key_exists($from, self::ORIGINS) ? $from : null;
    }

    /**
     * Where Back goes, and what the button says.
     *
     * @return array{label: string, url: string}
     */
    public static function back(?string $from): array
    {
        // Arrived by a link that said nothing, by a refresh, or by typing the
        // address: the module's own list is the honest answer.
        [$label, $route] = self::ORIGINS[self::name($from)] ?? ['Back to jobs', 'jobs.index'];

        return ['label' => $label, 'url' => route($route)];
    }

    /** The job detail, keeping whatever trail there is. */
    public static function jobUrl(int $jobId, ?string $from): string
    {
        return route('jobs.show', array_filter([
            'job' => $jobId,
            'from' => self::name($from),
        ]));
    }
}
