<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientAddress;
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
    public function store(Request $request, Client $client): RedirectResponse
    {
        abort_unless($client->user_id === $request->user()->id, 403);

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

        // The first site a client gets is its primary — the one a project and
        // then a job default to.
        $isFirst = ! $client->addresses()->exists();

        $address = $client->addresses()->create([
            'label' => trim($data['label']),
            'address' => $data['address'],
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'is_primary' => $isFirst,
            'position' => (int) $client->addresses()->max('position') + ($isFirst ? 0 : 1),
        ]);

        return back()->with('success', "“{$address->display()}” was added to {$client->name}.");
    }

    /**
     * Removing a site from the book.
     *
     * Refused while a job is standing on it. `job_addresses` cascades, so the
     * delete would go through and quietly take that job's site with it — the
     * job would keep its printed `location` and have nothing behind it.
     *
     * Removing the primary promotes the next one, because "the site everything
     * defaults to" has to be a site that exists.
     */
    public function destroy(Request $request, Client $client, ClientAddress $address): RedirectResponse
    {
        abort_unless($client->user_id === $request->user()->id, 403);
        abort_unless($address->client_id === $client->id, 404);

        $jobs = $address->jobs()->count();

        if ($jobs > 0) {
            return back()->with(
                'warning',
                "“{$address->display()}” is where ".$jobs.' '.str('job')->plural($jobs).
                ' runs. Move those first, or keep the site.',
            );
        }

        $display = $address->display();
        $wasPrimary = $address->is_primary;

        $address->delete();

        if ($wasPrimary) {
            $client->addresses()->oldest('position')->oldest('id')->first()
                ?->update(['is_primary' => true]);
        }

        return back()->with('warning', "“{$display}” was removed from {$client->name}.");
    }
}
