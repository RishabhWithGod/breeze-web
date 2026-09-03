<?php

namespace App\Services\Clients;

use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Clients are projects.
 *
 * The `projects` table is the client register — a project's `name` *is* the
 * client's name (Create Client writes both from one field). Jobs, estimates
 * and invoices therefore pick a client from here instead of repeating the
 * name as free text: they post a `project_id`, and their own `client` column
 * is a snapshot written from this directory, never typed by hand.
 *
 * The snapshot columns stay because they are what every list, filter and
 * printed document already reads, and because a client renamed later should
 * not silently rewrite invoices that were already sent under the old name.
 */
class ClientDirectory
{
    /**
     * Options for the single Client select every intake form now shows, each
     * carrying what the form fills in once that client is picked.
     */
    public function options(): Collection
    {
        return Project::with(['addresses', 'selectedUpload', 'primaryUpload'])
            ->orderBy('name')
            ->get(['id', 'name', 'project_type', 'selected_upload_id'])
            ->map(fn (Project $client) => [
                'id' => $client->id,
                'name' => $client->name,
                'projectType' => $client->project_type,
                /*
                 * The drawing this client's work is taken off — the one chosen
                 * on their screen, or the first on record. Picking the client
                 * fills it in, so the usual case takes no second choice.
                 */
                'defaultUploadId' => $client->takeoffDrawing()?->id,
                // Every site this client has work at. The job form offers these
                // rather than asking anyone to retype an address already on file.
                'addresses' => $client->addresses->map(fn ($address) => [
                    'id' => $address->id,
                    'label' => $address->label,
                    'address' => $address->address,
                    'display' => $address->display(),
                    'isPrimary' => $address->is_primary,
                ])->all(),
            ]);
    }

    /** The picked client's name, for the snapshot column. Null when nothing is picked. */
    public function nameFor(int|string|null $clientId): ?string
    {
        if (blank($clientId)) {
            return null;
        }

        return Project::whereKey($clientId)->value('name');
    }

    /**
     * Fills a validated intake payload's `client` snapshot from its
     * `project_id`. Leaves an existing snapshot alone when no client is
     * picked, so legacy records edited without one keep the name they have.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function withClientSnapshot(array $data): array
    {
        $name = $this->nameFor($data['project_id'] ?? null);

        if ($name !== null) {
            $data['client'] = $name;
        }

        return $data;
    }
}
