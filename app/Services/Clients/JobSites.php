<?php

namespace App\Services\Clients;

use App\Models\ClientAddress;
use App\Models\Job;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The sites a job is at.
 *
 * A job's address always comes from its own client's book, never from anywhere
 * else — the form only offers those, and this refuses anything else rather
 * than trusting the ids that arrived.
 */
class JobSites
{
    /**
     * The client's own addresses, in the order they were picked.
     *
     * @param  list<int>  $addressIds
     * @return Collection<int, ClientAddress>
     *
     * @throws ValidationException when an id is not one of this client's sites
     */
    public function resolve(int $clientId, array $addressIds): Collection
    {
        $addresses = ClientAddress::whereIn('id', $addressIds)
            ->where('client_id', $clientId)
            ->get()
            ->keyBy('id');

        // Ordered by the picking, not by id: the first one becomes the job's
        // snapshot, so which one came first is a real decision.
        $ordered = collect($addressIds)
            ->map(fn (int $id) => $addresses->get($id))
            ->filter()
            ->values();

        if ($ordered->count() !== count($addressIds)) {
            throw ValidationException::withMessages([
                'address_ids' => 'Those sites do not all belong to the selected client.',
            ]);
        }

        return $ordered;
    }

    /**
     * Attaches the sites and writes the job's own address snapshot.
     *
     * The snapshot is the first site's address. It is stored rather than read
     * through the relation because scheduling, time tracking and a printed job
     * sheet all read `location`, and none of them should change because someone
     * later corrected a typo in the client's address book.
     *
     * @param  Collection<int, ClientAddress>  $addresses
     */
    public function attach(Job $job, Collection $addresses): void
    {
        $job->addresses()->sync(
            $addresses->values()
                ->mapWithKeys(fn (ClientAddress $address, int $position) => [
                    $address->id => ['position' => $position],
                ])
                ->all()
        );

        $primary = $addresses->first();

        $job->update([
            'location' => $primary?->address,
            'latitude' => $primary?->latitude,
            'longitude' => $primary?->longitude,
        ]);
    }
}
