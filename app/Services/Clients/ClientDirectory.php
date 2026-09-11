<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The client register.
 *
 * A client is who the work is for. Their projects are the pieces of work, and
 * every drawing, takeoff, estimate and job hangs off one of those — not off the
 * client. So this answers only the two questions a form asks about a client:
 * who is on the register, and where do they have work — for one manager's own
 * register, never every client in the system.
 *
 * Jobs, estimates and invoices keep a `client` name snapshot of their own. It
 * stays because it is what every list, filter and printed document reads, and
 * because renaming a client should not silently rewrite invoices already sent
 * under the old name.
 */
class ClientDirectory
{
    /**
     * Options for the Client select every intake form shows, each carrying what
     * the form fills in once that client is picked.
     */
    public function options(User $user): Collection
    {
        return Client::with('addresses')
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Client $client) => [
                'id' => $client->id,
                'name' => $client->name,
                /*
                 * Every site this client has work at — one book, shared by all
                 * their projects, so an address on file is never retyped.
                 */
                'addresses' => $client->addresses->map(fn ($address) => [
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

    /**
     * The picked client's name, for the snapshot column. Null when nothing is
     * picked — or when the id names somebody else's client, which is
     * indistinguishable here from nothing being picked at all.
     */
    public function nameFor(int|string|null $clientId, User $user): ?string
    {
        if (blank($clientId)) {
            return null;
        }

        return Client::whereKey($clientId)->where('user_id', $user->id)->value('name');
    }

    /**
     * Fills a validated payload's `client` snapshot from its `client_id`.
     * Leaves an existing snapshot alone when no client is picked, so a record
     * edited without one keeps the name it has.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function withClientSnapshot(array $data, User $user): array
    {
        $name = $this->nameFor($data['client_id'] ?? null, $user);

        if ($name !== null) {
            $data['client'] = $name;
        }

        return $data;
    }
}
