<?php

namespace App\Services\Clients;

use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * The projects a piece of work can be raised against.
 *
 * A project is what a drawing is taken off — every upload, takeoff, estimate
 * and job belongs to one. Forms pick a client first and then one of their
 * projects, so each option carries the client it is under and the sites it
 * runs at, and the form narrows itself rather than asking the same question
 * twice.
 */
class ProjectDirectory
{
    /** Every project, with what a form fills in once one is picked. */
    public function options(): Collection
    {
        return Project::with(['addresses', 'clientRecord:id,name', 'selectedUpload', 'primaryUpload'])
            ->orderBy('name')
            ->get(['id', 'client_id', 'name', 'project_type', 'selected_upload_id'])
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'clientId' => $project->client_id,
                /*
                 * Whose project it is. A project's name only means something
                 * beside its client — two clients can both have a "Phase 2" —
                 * so every screen that shows one can say both.
                 */
                'clientName' => $project->clientRecord?->name ?? $project->client,
                'name' => $project->name,
                'projectType' => $project->project_type,
                /*
                 * The drawing this project's work is taken off — the one chosen
                 * on its screen, or the first on record. Picking the project
                 * fills it in, so the usual case takes no second choice.
                 */
                'defaultUploadId' => $project->takeoffDrawing()?->id,
                /*
                 * Its client's address book. A project and the job on it are at
                 * the same place, so the project keeps no list of its own — the
                 * job picks from here.
                 */
                'addresses' => $project->addresses->map(fn ($address) => [
                    'id' => $address->id,
                    'label' => $address->label,
                    'address' => $address->address,
                    'display' => $address->display(),
                    // What a job raised here defaults its own type to.
                    'siteType' => $address->site_type,
                    'isPrimary' => $address->is_primary,
                    'latitude' => $address->latitude === null ? null : (float) $address->latitude,
                    'longitude' => $address->longitude === null ? null : (float) $address->longitude,
                    'placeId' => $address->place_id,
                ])->all(),
            ]);
    }
}
