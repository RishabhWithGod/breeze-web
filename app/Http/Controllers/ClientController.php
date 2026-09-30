<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\Invoice;
use App\Models\ProjectActivity;
use App\Models\Team;
use App\Rules\UsPhoneNumber;
use App\Services\Takeoff\TakeoffFlow;
use App\Support\CompanyRule;
use App\Support\Ownership;
use App\Support\UsPhone;
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
            ->with(['primaryAddress', 'primaryContact'])
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
                'contactName' => $client->primaryContact?->name,
                'contactEmail' => $client->primaryContact?->email,
                'contactPhone' => $client->primaryContact?->phone,
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
            // The crew register, for the "which team normally works this
            // client's sites" picker — with a way to add one inline, the same
            // as a job's own team picker.
            'teams' => Team::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $client = $request->user()->clients()->create([
            'name' => $data['name'],
            'website' => $this->normalizeWebsite($data['website'] ?? null),
            'notes' => $this->orNull($data['notes'] ?? null),
            'labor_rate' => $data['labor_rate'] ?? null,
            'team_id' => $data['team_id'] ?? null,
        ]);

        $this->writeAddresses($client, $data['addresses'] ?? []);
        $this->writePrimaryContact($client, $data['contact_email'] ?? null, $data['contact_phone'] ?? null);

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
            'contacts',
            'projects' => fn ($query) => $query->withCount(['uploads', 'aiResults']),
        ]);

        $projectCounts = $client->projects->countBy('status');
        $completedProjects = $projectCounts->get('completed', 0) + $projectCounts->get('converted', 0);
        $failedProjects = $projectCounts->get('failed', 0);
        $primaryContact = $client->contacts->firstWhere('is_primary', true);

        return Inertia::render('ClientShow', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'website' => $client->website,
                // The client's own line — its primary contact's, read here
                // too so the Client Information card doesn't send the reader
                // to the Contacts card just to see it.
                'contactEmail' => $primaryContact?->email,
                'contactPhone' => $primaryContact?->phone,
                'notes' => $client->notes,
                'createdAt' => $client->created_at?->toISOString(),
                'contacts' => $client->contacts->map(fn ($contact) => [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'initials' => $contact->initials(),
                    'role' => $contact->role,
                    'email' => $contact->email,
                    'phone' => $contact->phone,
                    'isPrimary' => $contact->is_primary,
                ])->values(),
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
            /*
             * At-a-glance counts, over and above the list above. "Active" is
             * everything not finished and not failed — there is no "on hold"
             * status on a project today, so that tile always reads zero
             * rather than a guess.
             */
            'projectSummary' => [
                'total' => $client->projects->count(),
                'active' => $client->projects->count() - $completedProjects - $failedProjects,
                'completed' => $completedProjects,
                'onHold' => 0,
            ],
            /*
             * Every invoice raised against this client, whatever project or
             * job it came off — a sent-or-paid one's `total - paid_amount`.
             * A draft has not been asked for yet, so it owes nothing.
             */
            'outstandingBalance' => (float) Invoice::where('client_id', $client->id)
                ->where('status', '!=', Invoice::STATUS_DRAFT)
                ->get()
                ->sum(fn (Invoice $invoice) => $invoice->outstanding()),
            /*
             * What has actually happened, read off the client's own projects
             * — the only place an event like this is recorded today. Other
             * things worth knowing about a client (an invoice raised, an
             * estimate sent) aren't logged anywhere yet, so they don't
             * appear here rather than being guessed at.
             */
            'activity' => ProjectActivity::whereIn('project_id', $client->projects->pluck('id'))
                ->latest('occurred_at')
                ->take(10)
                ->get()
                ->map(fn (ProjectActivity $entry) => [
                    'id' => $entry->id,
                    'title' => $entry->title,
                    'description' => $entry->description,
                    'tone' => $entry->tone,
                    'occurredAt' => $entry->occurred_at?->toISOString(),
                ])->values(),
        ]);
    }

    public function edit(Request $request, Client $client): Response
    {
        $this->authoriseOwner($request, $client);

        // The count is what lets the screen grey out a site it cannot
        // remove, instead of offering the button and refusing afterwards —
        // same as the client's own screen (`show()`, below).
        $client->load(['addresses' => fn ($query) => $query->withCount('jobs'), 'contacts']);
        $primaryContact = $client->contacts->firstWhere('is_primary', true);

        return Inertia::render('ClientEdit', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'website' => $client->website,
                'contactEmail' => $primaryContact?->email,
                'contactPhone' => $primaryContact?->phone,
                'notes' => $client->notes,
                // Resolved, not raw: a client that has never set its own
                // rate shows the configured default rather than a blank box.
                'laborRate' => $client->effectiveLaborRate(),
                'teamId' => $client->team_id,
                'contacts' => $client->contacts->map(fn ($contact) => [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'initials' => $contact->initials(),
                    'role' => $contact->role,
                    'email' => $contact->email,
                    'phone' => $contact->phone,
                    'isPrimary' => $contact->is_primary,
                ])->values(),
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
            // The crew register, for the same "which team works this
            // client's sites" picker Add Client offers.
            'teams' => Team::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $this->authoriseOwner($request, $client);

        $data = $this->validated($request, $client);

        $client->update([
            'name' => $data['name'],
            'website' => $this->normalizeWebsite($data['website'] ?? null),
            'notes' => $this->orNull($data['notes'] ?? null),
            'labor_rate' => $data['labor_rate'] ?? null,
            'team_id' => $data['team_id'] ?? null,
        ]);

        $this->writePrimaryContact($client, $data['contact_email'] ?? null, $data['contact_phone'] ?? null);

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

        $name = $client->name;
        $client->delete();

        return redirect()
            ->route('clients.index')
            ->with('warning', $projects > 0
                ? "“{$name}” was removed, along with ".$projects.' '.str('project')->plural($projects).
                    ' and everything under '.($projects === 1 ? 'it' : 'them').'.'
                : "“{$name}” was removed from the register.");
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
                    ->whereIn('user_id', Ownership::userIdList($request->user()))
                    ->ignore($client),
            ],
            /*
             * Where to look them up online. Optional — not every client has
             * a site worth linking.
             */
            'website' => ['nullable', 'string', 'max:255'],
            /*
             * A quick way in to their primary contact — the one on the
             * Contacts card that's flagged primary — without opening it.
             * Both optional, same as everything else about a contact.
             */
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40', new UsPhoneNumber],
            'notes' => ['nullable', 'string', 'max:2000'],
            /*
             * What an hour of this client's labor is billed at. Optional —
             * left blank, every estimate on this client's projects falls
             * back to the usual rate; set, that exact figure prices every
             * labor line from here on.
             */
            'labor_rate' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            /*
             * The crew this client's projects are normally staffed from.
             * Optional — a client can be on the register before anyone
             * decides who works their sites.
             */
            'team_id' => ['nullable', 'integer', CompanyRule::exists('teams')],
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
            'contact_email.email' => 'That does not look like an email address',
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

    /**
     * The quick email/phone fields on the client's own form, written onto
     * their primary contact rather than the client itself — the Contacts
     * card is the real record of who these belong to, this is just the fast
     * way to set them without opening it.
     *
     * A client with no contact yet gets one, named after the client itself,
     * the moment either field is filled in — the same way the first address
     * given becomes the primary site.
     */
    private function writePrimaryContact(Client $client, ?string $email, ?string $phone): void
    {
        $email = $this->orNull($email);
        $phone = UsPhone::format($this->orNull($phone));

        $primary = $client->contacts()->where('is_primary', true)->first();

        if ($primary !== null) {
            $primary->update(['email' => $email, 'phone' => $phone]);

            return;
        }

        if ($email === null && $phone === null) {
            return;
        }

        $client->contacts()->create([
            'name' => $client->name,
            'email' => $email,
            'phone' => $phone,
            'is_primary' => true,
            'position' => 0,
        ]);
    }

    /** An untyped optional field is nothing, not an empty string. */
    private function orNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * "coldbar.com" and "https://coldbar.com" are the same address typed two
     * ways — stored with a scheme so the link on the client's own screen
     * actually goes somewhere instead of resolving against this app's host.
     */
    private function normalizeWebsite(?string $value): ?string
    {
        $trimmed = $this->orNull($value);

        if ($trimmed === null) {
            return null;
        }

        return str_contains($trimmed, '://') ? $trimmed : "https://{$trimmed}";
    }

    private function authoriseOwner(Request $request, Client $client): void
    {
        abort_unless(Ownership::owns($request->user(), $client->user_id), 403);
    }
}
