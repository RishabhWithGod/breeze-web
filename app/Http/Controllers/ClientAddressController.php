<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Adding a site to a client without leaving the screen that needed it.
 *
 * Raising a job for a site the client's record does not have yet is normal —
 * the address arrives with the job, not before it. Sending someone to the
 * client screen and back to record it loses whatever they had already typed,
 * so it is recorded from wherever they are.
 */
class ClientAddressController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'address' => ['required', 'string', 'max:160'],
            /*
             * Set only when the address was picked from the lookup — but never
             * one without the other, or the site would carry half a point.
             */
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ], [
            'label.required' => 'Name this site.',
            'address.required' => 'Enter the address.',
        ]);

        // The first site a client gets is its primary, and is mirrored onto the
        // client itself — that is what every list and search reads.
        $isFirst = ! $project->addresses()->exists();

        $address = $project->addresses()->create([
            'label' => trim($data['label']),
            'address' => $data['address'],
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'is_primary' => $isFirst,
            'position' => (int) $project->addresses()->max('position') + ($isFirst ? 0 : 1),
        ]);

        if ($isFirst) {
            $project->update([
                'location' => $address->address,
                'latitude' => $address->latitude,
                'longitude' => $address->longitude,
            ]);
        }

        return back()->with('success', "“{$address->display()}” was added to {$project->name}.");
    }
}
