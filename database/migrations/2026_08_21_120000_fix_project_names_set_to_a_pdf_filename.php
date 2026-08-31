<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Before the AI takeoff pipeline stopped overwriting `projects.name` with the
 * engine's own project_name (itself derived from the drawing's filename),
 * some projects were left with a PDF filename as their display name.
 *
 * This identifies those rows by the one reliable signal available — `name`
 * ending in `.pdf` — and:
 *   1. Preserves the filename into `drawing_name`, where that column is still
 *      empty, so nothing is lost.
 *   2. Strips the extension from `name` so it reads as a name rather than a
 *      file.
 *
 * A project whose name simply doesn't end in `.pdf` is never touched, so a
 * name a user has already set stays exactly as they left it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('projects')
            ->whereRaw('LOWER(name) LIKE ?', ['%.pdf'])
            ->orderBy('id')
            ->get(['id', 'name', 'drawing_name'])
            ->each(function ($project) {
                $fixedName = trim((string) preg_replace('/\.pdf$/i', '', $project->name));

                DB::table('projects')
                    ->where('id', $project->id)
                    ->update([
                        'drawing_name' => $project->drawing_name ?: $project->name,
                        'name' => $fixedName !== '' ? $fixedName : $project->name,
                    ]);
            });
    }

    /**
     * Not reversible: the original filename-as-name value carries no
     * information that isn't already preserved (and now correctly labelled)
     * in `drawing_name`, so there is nothing meaningful to roll back to.
     */
    public function down(): void {}
};
