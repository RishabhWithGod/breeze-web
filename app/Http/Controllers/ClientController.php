<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientAddress;
use App\Services\Takeoff\TakeoffFlow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The client register.
 *
 * A client is who the work is for, and nothing more: a name, a note, and the
 * address book their projects draw on. Everything that has a drawing, a
 * takeoff, an estimate or a job behind it belongs to one of their projects,
 * not to them — which is why this screen is short and their detail screen is
 * mostly a list of projects.
 */
class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:120']]);
        $search = trim($filters['search'] ?? '');

        $clients = $request->user()->clients()
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            // What the list is for: who has work on, and where.
            ->withCount(['projects', 'addresses'])
            ->with('primaryAddress')
            /*
             * Most recently touched first. `User::clients()` orders by name,
             * which is a fine way to look someone up and a poor way to find
             * what you were just working on — so this reorders.
             */
            ->reorder()
            ->latest('updated_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Client $client) => [
                'id' => $client->id,
                'name' => $client->name,
                'projectCount' => $client->projects_count,
                'siteCount' => $client->addresses_count,
                'primarySite' => $client->primaryAddress?->display(),
            ]);

        return Inertia::render('Clients', [
            // Wrapped, not handed over raw: a bare paginator serialises flat
            // and the screen reads `meta.current_page` to draw its pager.
            'clients' => JsonResource::collection($clients),
            'filters' => ['search' => $search],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('ClientCreate', [
            /*
             * A takeoff already on the go is worth saying out loud here:
             * starting a second client is a normal thing to do, but doing it by
             * accident and losing track of the first is not.
             */
            'unfinishedTakeoff' => app(TakeoffFlow::class)->inProgress($request),
            // What the labor rate field starts at — the configured default,
            // shown as a real number rather than an empty box, so leaving it
            // alone is a decision rather than an oversight.
            'defaultLaborRate' => (float) config('ai.estimating.labor_rate'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $client = $request->user()->clients()->create([
            'name' => $data['name'],
            'notes' => $this->orNull($data['notes'] ?? null),
            'labor_rate' => $data['labor_rate'] ?? null,
        ]);

        $this->writeAddresses($client, $data['addresses'] ?? []);

        /*
         * Straight on to the project form, with the client already filled in.
         * A client on its own is not the point — the work hangs off a project,
         * so the next step is offered rather than waiting behind a button on a
         * screen that has nothing on it yet.
         */
        return redirect()
            ->route('projects.create', ['client' => $client->id])
            ->with('success', "“{$client->name}” was added. Add their first project.");
    }

    public function show(Request $request, Client $client): Response
    {
        $this->authoriseOwner($request, $client);

        $client->load([
            // The count is what lets the screen grey out a site it cannot
            // remove, instead of offering the button and refusing afterwards.
            'addresses' => fn ($query) => $query->withCount('jobs'),
            'projects' => fn ($query) => $query->withCount(['uploads', 'aiResults']),
        ]);

        return Inertia::render('ClientShow', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'notes' => $client->notes,
                'createdAt' => $client->created_at?->toISOString(),
                'addresses' => $client->addresses->map(fn ($address) => [
                    'id' => $address->id,
                    'label' => $address->label,
                    'address' => $address->address,
                    'display' => $address->display(),
                    'siteType' => $address->site_type,
                    'isPrimary' => $address->is_primary,
                    'latitude' => $address->latitude === null ? null : (float) $address->latitude,
                    'longitude' => $address->longitude === null ? null : (float) $address->longitude,
                    'placeId' => $address->place_id,
                    // `job_addresses` cascades, so a site with work on it
                    // cannot go — see ClientAddressController::destroy.
                    'jobCount' => $address->jobs_count,
                ])->values(),
            ],
            /*
             * The reason this screen exists. A client's work is their projects,
             * the way a job's work is its tasks — so they are listed here, not
             * hidden behind another click.
             */
            'projects' => $client->projects->map(fn ($project) => [
                'id' => $project->id,
                'name' => $project->name,
                'code' => $project->code,
                'status' => $project->status,
                'projectType' => $project->project_type,
                'drawingCount' => $project->uploads_count,
                'takeoffCount' => $project->ai_results_count,
                'createdAt' => $project->created_at?->toISOString(),
            ])->values(),
        ]);
    }

    public function edit(Request $request, Client $client): Response
    {
        $this->authoriseOwner($request, $client);

        // The count is what lets the screen grey out a site it cannot
        // remove, instead of offering the button and refusing afterwards —
        // same as the client's own screen (`show()`, below).
        $client->load(['addresses' => fn ($query) => $query->withCount('jobs')]);

        return Inertia::render('ClientEdit', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'notes' => $client->notes,
                // Resolved, not raw: a client that has never set its own
                // rate shows the configured default rather than a blank box.
                'laborRate' => $client->effectiveLaborRate(),
                'addresses' => $client->addresses->map(fn ($address) => [
                    'id' => $address->id,
                    'label' => $address->label,
                    'address' => $address->address,
                    'display' => $address->display(),
                    'siteType' => $address->site_type,
                    'isPrimary' => $address->is_primary,
                    'latitude' => $address->latitude === null ? null : (float) $address->latitude,
                    'longitude' => $address->longitude === null ? null : (float) $address->longitude,
                    'placeId' => $address->place_id,
                    // `job_addresses` cascades, so a site with work on it
                    // cannot go — see ClientAddressController::destroy.
                    'jobCount' => $address->jobs_count,
                ])->values(),
            ],
        ]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $this->authoriseOwner($request, $client);

        $data = $this->validated($request, $client);

        $client->update([
            'name' => $data['name'],
            'notes' => $this->orNull($data['notes'] ?? null),
            'labor_rate' => $data['labor_rate'] ?? null,
        ]);

        return redirect()
            ->route('clients.show', $client)
            ->with('success', "“{$client->name}” was updated.");
    }

    /**
     * Removing a client from the register.
     *
     * Only one with no projects. Deleting anyone else would take their
     * drawings, takeoffs, estimates and jobs with them — a delete that looks
     * tidy and quietly removes years of work.
     */
    public function destroy(Request $request, Client $client): RedirectResponse
    {
        $this->authoriseOwner($request, $client);

        $projects = $client->projects()->count();

        if ($projects > 0) {
            return back()->with(
                'warning',
                "“{$client->name}” has ".$projects.' '.str('project')->plural($projects).
                '. Remove those first, or keep the client.',
            );
        }

        $name = $client->name;
        $client->delete();

        return redirect()
            ->route('clients.index')
            ->with('warning', "“{$name}” was removed from the register.");
    }

    /**
     * The same rules whether the client is being added or corrected.
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
            /*
             * What an hour of this client's labor is billed at. Optional —
             * left blank, every estimate on this client's projects falls
             * back to the usual rate; set, that exact figure prices every
             * labor line from here on.
             */
            'labor_rate' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            /*
             * The address book, filled in as the client is opened. It can be
             * empty — a client can be on the register before anyone knows where
             * their work is — and sites are added later from wherever they are
             * needed.
             */
            'addresses' => ['nullable', 'array', 'max:25'],
            'addresses.*.label' => ['required', 'string', 'max:80'],
            'addresses.*.address' => ['required', 'string', 'max:160'],
            /*
             * What kind of building it is. Optional, because a site can be
             * recorded before anyone has been to it — a job raised there then
             * asks the question instead of guessing.
             */
            'addresses.*.site_type' => ['nullable', Rule::in(ClientAddress::TYPES)],
            /*
             * Set only when the address was picked from the lookup, so both are
             * optional — but never one without the other, or the record would
             * carry half a point.
             */
            'addresses.*.latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:addresses.*.longitude'],
            'addresses.*.longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:addresses.*.latitude'],
            /*
             * Google's ids are opaque, so the only honest check is shape: a
             * bounded printable string. Never trusted as proof of anything —
             * the coordinates beside it are range-checked on their own.
             */
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

    private function authoriseOwner(Request $request, Client $client): void
    {
        abort_unless($client->user_id === $request->user()->id, 403);
    }
}
