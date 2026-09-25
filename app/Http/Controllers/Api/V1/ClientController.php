<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Clients, as the mobile app sees them — the exact same scope, sort, and
 * eager loads as the web `ClientController::index()`: only the signed-in
 * user's own register (`$user->clients()`, single-owner `user_id`, no
 * company/team concept on this model at all), most-recently-touched first
 * (`updated_at` desc, then `id` desc — mirrors web's own comment: "a fine
 * way to look someone up and a poor way to find what you were just working
 * on"). No search param yet (web's `search` filter is left for a later
 * pass — the mobile list starts read-only, matching the Jobs/Estimates
 * endpoints' own precedent of filtering client-side for now).
 */
class ClientController extends Controller
{
    use ApiResponses;

    public function index(Request $request): JsonResponse
    {
        $clients = $request->user()->clients()
            ->withCount(['projects', 'addresses'])
            ->with('primaryAddress')
            ->reorder()
            ->latest('updated_at')
            ->latest('id')
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return $this->ok([
            'clients' => $clients->getCollection()->map(fn (Client $client) => [
                'id' => $client->id,
                'name' => $client->name,
                'projectCount' => $client->projects_count,
                'siteCount' => $client->addresses_count,
                'primarySite' => $client->primaryAddress?->display(),
                'createdAt' => $client->created_at?->toISOString(),
            ])->all(),
            'meta' => [
                'currentPage' => $clients->currentPage(),
                'lastPage' => $clients->lastPage(),
                'perPage' => $clients->perPage(),
                'total' => $clients->total(),
                // What the Add Client form's labor rate field starts at —
                // same config web's own `ClientController::create()` reads,
                // sent here instead since mobile has no separate "new
                // client" page load to carry it on.
                'defaultLaborRate' => (float) config('ai.estimating.labor_rate'),
            ],
        ]);
    }

    /**
     * The detail screen's shape. This model genuinely has no email/phone/
     * company fields — a client here is just a name, notes, a labor rate,
     * and an address book — so unlike Job/Estimate this isn't a case of
     * `index` trimming a richer row; there is no contact info to send.
     */
    public function show(Request $request, Client $client): JsonResponse
    {
        abort_unless($client->user_id === $request->user()->id, 403);

        return $this->ok($this->present($client));
    }

    /**
     * `Api\V1\ClientController::store()` — mobile's counterpart to web's
     * own `ClientController::store()`. Same validation, same "first address
     * given is primary" rule, same fields; the only difference is the
     * response is this endpoint's own JSON envelope instead of a redirect,
     * since mobile decides what screen comes next itself (the Client →
     * Project hop) rather than following a server redirect.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $client = $request->user()->clients()->create([
            'name' => $data['name'],
            'notes' => $this->orNull($data['notes'] ?? null),
            'labor_rate' => $data['labor_rate'] ?? null,
        ]);

        $this->writeAddresses($client, $data['addresses'] ?? []);

        return $this->created($this->present($client), "\"{$client->name}\" was added.");
    }

    /**
     * `Api\V1\ClientController::update()` — same fields as `store()`'s
     * client-level ones (name/notes/labor_rate); web's own Edit screen has
     * no addresses in its own submit either — those go through
     * `ClientAddressController` one site at a time, same as here.
     */
    public function update(Request $request, Client $client): JsonResponse
    {
        abort_unless($client->user_id === $request->user()->id, 403);

        $data = $this->validated($request, $client);

        $client->update([
            'name' => $data['name'],
            'notes' => $this->orNull($data['notes'] ?? null),
            'labor_rate' => $data['labor_rate'] ?? null,
        ]);

        return $this->ok($this->present($client->fresh()), "\"{$client->name}\" was updated.");
    }

    /** @return array<string, mixed> */
    private function present(Client $client): array
    {
        $client->loadMissing('addresses');

        return [
            'id' => $client->id,
            'name' => $client->name,
            'notes' => $client->notes,
            'laborRate' => $client->effectiveLaborRate(),
            'addresses' => $client->addresses->map(fn (ClientAddress $address) => [
                'id' => $address->id,
                'label' => $address->label,
                'address' => $address->address,
                'siteType' => $address->site_type,
                'isPrimary' => (bool) $address->is_primary,
            ])->all(),
            'projects' => $client->projects()->latest('id')->get()->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'updatedAt' => $project->updated_at?->toISOString(),
            ])->all(),
            'createdAt' => $client->created_at?->toISOString(),
        ];
    }

    /**
     * The same rules whether the client is being added or corrected — a
     * straight mirror of web's own `ClientController::validated()`.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Client $client = null): array
    {
        // The form posts an empty string when the field is left blank.
        if ($request->input('labor_rate') === '') {
            $request->merge(['labor_rate' => null]);
        }

        return $request->validate([
            'name' => [
                'required', 'string', 'min:2', 'max:160',
                // A client renaming themselves is not a clash with themselves.
                Rule::unique('clients', 'name')
                    ->where('user_id', $request->user()->id)
                    ->ignore($client),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            'labor_rate' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'addresses' => ['nullable', 'array', 'max:25'],
            'addresses.*.label' => ['required', 'string', 'max:80'],
            'addresses.*.address' => ['required', 'string', 'max:160'],
            'addresses.*.site_type' => ['nullable', Rule::in(ClientAddress::TYPES)],
            'addresses.*.latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:addresses.*.longitude'],
            'addresses.*.longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:addresses.*.latitude'],
            'addresses.*.place_id' => ['nullable', 'string', 'max:512', 'regex:/^[A-Za-z0-9_\\-]+$/'],
        ], [
            'name.required' => 'Client name is required',
            'name.unique' => 'A client with that name is already on the register',
            'labor_rate.numeric' => 'Enter a valid hourly rate',
            'labor_rate.min' => 'Rate cannot be negative',
            'addresses.*.label.required' => 'Name this site, or remove the row.',
            'addresses.*.address.required' => 'Enter the address, or remove the row.',
        ]);
    }

    /** @param  array<int, array<string, mixed>>  $addresses */
    private function writeAddresses(Client $client, array $addresses): void
    {
        foreach ($addresses as $position => $address) {
            $client->addresses()->create([
                'label' => trim($address['label']),
                'address' => $address['address'],
                'site_type' => $address['site_type'] ?? null,
                'latitude' => $address['latitude'] ?? null,
                'longitude' => $address['longitude'] ?? null,
                'place_id' => $address['place_id'] ?? null,
                // The first one given is the one a project defaults to.
                'is_primary' => $position === 0,
                'position' => $position,
            ]);
        }
    }

    /** An untyped optional field is nothing, not an empty string. */
    private function orNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
