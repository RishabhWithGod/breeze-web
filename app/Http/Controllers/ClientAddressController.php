<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\Job;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
             * What kind of building it is. Optional — a site can go on the book
             * before anyone has been to it, and the job raised there asks then.
             */
            'site_type' => ['nullable', Rule::in(ClientAddress::TYPES)],
            /*
             * Set only when the address was picked from the lookup — but never
             * one without the other, or the site would carry half a point.
             */
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            /*
             * Google's ids are opaque, so the only honest check is shape: a
             * bounded printable string. Never trusted as proof of anything —
             * the coordinates beside it are range-checked on their own.
             */
            'place_id' => ['nullable', 'string', 'max:512', 'regex:/^[A-Za-z0-9_\\-]+$/'],
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
            'site_type' => $data['site_type'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'place_id' => $data['place_id'] ?? null,
            'is_primary' => $isFirst,
            'position' => (int) $client->addresses()->max('position') + ($isFirst ? 0 : 1),
        ]);

        return back()->with('success', "“{$address->display()}” was added to {$client->name}.");
    }

    /**
     * Correcting a site, everywhere it is used.
     *
     * A job and a project each keep a snapshot of their address rather than
     * reading it through the book — scheduling, time tracking and a printed job
     * sheet all read `location`. Those snapshots are rewritten here, so fixing a
     * typo fixes it everywhere rather than leaving the old spelling on the work.
     *
     * That is a deliberate choice with a cost: a client who *moves* should not
     * have their finished jobs silently relocated. Moving is adding a site, not
     * editing one.
     */
    public function update(Request $request, Client $client, ClientAddress $address): RedirectResponse
    {
        abort_unless($client->user_id === $request->user()->id, 403);
        abort_unless($address->client_id === $client->id, 404);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'address' => ['required', 'string', 'max:160'],
            /*
             * What kind of building it is. Optional — a site can go on the book
             * before anyone has been to it, and the job raised there asks then.
             */
            'site_type' => ['nullable', Rule::in(ClientAddress::TYPES)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            /*
             * Google's ids are opaque, so the only honest check is shape: a
             * bounded printable string. Never trusted as proof of anything —
             * the coordinates beside it are range-checked on their own.
             */
            'place_id' => ['nullable', 'string', 'max:512', 'regex:/^[A-Za-z0-9_\\-]+$/'],
        ], [
            'label.required' => 'Name this site.',
            'address.required' => 'Enter the address.',
        ]);

        $wasAt = $address->address;

        DB::transaction(function () use ($address, $client, $data, $wasAt) {
            $address->update([
                'label' => trim($data['label']),
                'address' => $data['address'],
                'site_type' => $data['site_type'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'place_id' => $data['place_id'] ?? null,
            ]);

            /*
             * Every job standing on this site, by the link rather than by the
             * spelling — a job attached here is at this site whatever its
             * snapshot currently says.
             *
             * Read first, then written: MySQL refuses an UPDATE whose own
             * table appears in a subquery of the same statement.
             */
            $jobIds = $address->jobs()->pluck('work_jobs.id')->all();

            if ($jobIds !== []) {
                Job::whereKey($jobIds)->update([
                    'location' => $address->address,
                    'latitude' => $address->latitude,
                    'longitude' => $address->longitude,
                    'place_id' => $address->place_id,
                ]);
            }

            /*
             * Projects keep a snapshot too, but no link — they take the
             * client's primary site. Matched on the old spelling, which is the
             * only thing tying them to this row.
             */
            $client->projects()
                ->where('location', $wasAt)
                ->update([
                    'location' => $address->address,
                    'latitude' => $address->latitude,
                    'longitude' => $address->longitude,
                    'place_id' => $address->place_id,
                ]);
        });

        return back()->with('success', "“{$address->display()}” was updated.");
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
