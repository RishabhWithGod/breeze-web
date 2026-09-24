<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\Job;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Adding/editing/removing a site on a client's own address book — mobile's
 * counterpart to web's own `ClientAddressController`. Reached from the
 * mobile Continue-to-Job form's "Add location" flow, same reasoning web's
 * own doc comment gives: the address arrives with the job, not before it.
 */
class ClientAddressController extends Controller
{
    use ApiResponses;

    public function store(Request $request, Client $client): JsonResponse
    {
        abort_unless($client->user_id === $request->user()->id, 403);

        $data = $this->validated($request);
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

        return $this->created($this->present($address), "\"{$address->display()}\" was added to {$client->name}.");
    }

    public function update(Request $request, Client $client, ClientAddress $address): JsonResponse
    {
        abort_unless($client->user_id === $request->user()->id, 403);
        abort_unless($address->client_id === $client->id, 404);

        $data = $this->validated($request);
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

            $jobIds = $address->jobs()->pluck('work_jobs.id')->all();

            if ($jobIds !== []) {
                Job::whereKey($jobIds)->update([
                    'location' => $address->address,
                    'latitude' => $address->latitude,
                    'longitude' => $address->longitude,
                    'place_id' => $address->place_id,
                ]);
            }

            $client->projects()
                ->where('location', $wasAt)
                ->update([
                    'location' => $address->address,
                    'latitude' => $address->latitude,
                    'longitude' => $address->longitude,
                    'place_id' => $address->place_id,
                ]);
        });

        return $this->ok($this->present($address->fresh()), "\"{$address->display()}\" was updated.");
    }

    public function destroy(Request $request, Client $client, ClientAddress $address): JsonResponse
    {
        abort_unless($client->user_id === $request->user()->id, 403);
        abort_unless($address->client_id === $client->id, 404);

        $jobs = $address->jobs()->count();

        if ($jobs > 0) {
            return $this->fail(
                "\"{$address->display()}\" is where {$jobs} ".str('job')->plural($jobs).' runs. Move those first, or keep the site.',
                422,
            );
        }

        $display = $address->display();
        $wasPrimary = $address->is_primary;

        $address->delete();

        if ($wasPrimary) {
            $client->addresses()->oldest('position')->oldest('id')->first()?->update(['is_primary' => true]);
        }

        return $this->ok(null, "\"{$display}\" was removed from {$client->name}.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'address' => ['required', 'string', 'max:160'],
            'site_type' => ['nullable', Rule::in(ClientAddress::TYPES)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'place_id' => ['nullable', 'string', 'max:512', 'regex:/^[A-Za-z0-9_\-]+$/'],
        ], [
            'label.required' => 'Name this site.',
            'address.required' => 'Enter the address.',
        ]);
    }

    /** @return array<string, mixed> */
    private function present(ClientAddress $address): array
    {
        return [
            'id' => $address->id,
            'label' => $address->label,
            'address' => $address->address,
            'siteType' => $address->site_type,
            'isPrimary' => (bool) $address->is_primary,
        ];
    }
}
