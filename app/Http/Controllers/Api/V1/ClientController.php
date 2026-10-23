<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\Foreman;
use App\Models\Invoice;
use App\Models\ProjectActivity;
use App\Models\TeamInvitation;
use App\Rules\UsPhoneNumber;
use App\Support\UsPhone;
use App\Models\Project;
use App\Support\CompanyRule;
use App\Support\Ownership;
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
        abort_unless(Ownership::owns($request->user(), $client->user_id), 403);

        return $this->ok($this->present($client));
    }

    /**
     * `GET /clients/create-options` — web's `ClientController::create()` props:
     * what the labor rate starts at, everyone on the register (offered as this
     * client's members regardless of team), and invitees not yet on it.
     */
    public function createOptions(Request $request): JsonResponse
    {
        return $this->ok([
            'defaultLaborRate' => (float) config('ai.estimating.labor_rate'),
            'members' => Foreman::query()->orderBy('name')->get()->map(fn (Foreman $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'role' => $member->role,
                'roleLabel' => $member->roleLabel(),
                'teamId' => $member->team_id,
            ])->values(),
            // Shown, not selectable, until they accept.
            'pendingMembers' => TeamInvitation::query()
                ->where('status', TeamInvitation::STATUS_PENDING)
                ->where('expires_at', '>', now())
                ->orderBy('name')
                ->get()
                ->map(fn (TeamInvitation $invitation) => [
                    'id' => $invitation->id,
                    'name' => $invitation->name,
                    'roleLabel' => $invitation->role,
                ])->values(),
        ]);
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
        $data = $this->validated($request, null, requireCrew: true);

        $client = $request->user()->clients()->create([
            'name' => $data['name'],
            'website' => $this->normalizeWebsite($data['website'] ?? null),
            'notes' => $this->orNull($data['notes'] ?? null),
            'labor_rate' => $data['labor_rate'] ?? null,
        ]);

        // Who normally works this client's sites — on record for this client alone, not any team.
        $client->members()->sync($data['member_ids']);

        $this->writeAddresses($client, $data['addresses'] ?? []);
        $this->writePrimaryContact($client, $data['contact_email'] ?? null, $data['contact_phone'] ?? null);

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
        abort_unless(Ownership::owns($request->user(), $client->user_id), 403);

        $data = $this->validated($request, $client);

        $client->update([
            'name' => $data['name'],
            'website' => $this->normalizeWebsite($data['website'] ?? null),
            'notes' => $this->orNull($data['notes'] ?? null),
            'labor_rate' => $data['labor_rate'] ?? null,
        ]);

        $this->writePrimaryContact($client, $data['contact_email'] ?? null, $data['contact_phone'] ?? null);

        return $this->ok($this->present($client->fresh()), "\"{$client->name}\" was updated.");
    }

    /** `DELETE /clients/{client}` — removes the client along with its projects, as web does. */
    public function destroy(Request $request, Client $client): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $client->user_id), 403);

        $projects = $client->projects()->count();
        $name = $client->name;
        $client->delete();

        return $this->ok(null, $projects > 0
            ? "\"{$name}\" was removed, along with {$projects} ".str('project')->plural($projects).' and everything under '.($projects === 1 ? 'it' : 'them').'.'
            : "\"{$name}\" was removed from the register.");
    }

    /** @return array<string, mixed> */
    private function present(Client $client): array
    {
        $client->load([
            'addresses' => fn ($query) => $query->withCount('jobs'),
            'contacts',
            'projects' => fn ($query) => $query->withCount(['uploads', 'aiResults']),
            'members' => fn ($query) => $query->orderBy('name'),
        ]);

        $counts = $client->projects->countBy('status');
        $completed = $counts->get('completed', 0) + $counts->get('converted', 0);
        $failed = $counts->get('failed', 0);
        $primary = $client->contacts->firstWhere('is_primary', true);

        return [
            'id' => $client->id,
            'name' => $client->name,
            'website' => $client->website,
            // The client's own line — its primary contact's.
            'contactEmail' => $primary?->email,
            'contactPhone' => $primary?->phone,
            'notes' => $client->notes,
            'laborRate' => $client->effectiveLaborRate(),
            'contacts' => $client->contacts->map(fn ($contact) => [
                'id' => $contact->id,
                'name' => $contact->name,
                'role' => $contact->role,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'isPrimary' => (bool) $contact->is_primary,
            ])->values()->all(),
            'addresses' => $client->addresses->map(fn (ClientAddress $address) => [
                'id' => $address->id,
                'label' => $address->label,
                'address' => $address->address,
                'siteType' => $address->site_type,
                'isPrimary' => (bool) $address->is_primary,
                // A site with work on it cannot be removed.
                'jobCount' => $address->jobs_count,
            ])->all(),
            'members' => $client->members->map(fn (Foreman $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'role' => $member->role,
                'roleLabel' => $member->roleLabel(),
            ])->values()->all(),
            'projects' => $client->projects->sortByDesc('id')->values()->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'code' => $project->code,
                'status' => $project->status,
                'projectType' => $project->project_type,
                'drawingCount' => $project->uploads_count,
                'takeoffCount' => $project->ai_results_count,
                'updatedAt' => $project->updated_at?->toISOString(),
                'createdAt' => $project->created_at?->toISOString(),
            ])->all(),
            'projectSummary' => [
                'total' => $client->projects->count(),
                'active' => $client->projects->count() - $completed - $failed,
                'completed' => $completed,
                'onHold' => 0,
            ],
            // A sent-or-paid invoice's `total - paid_amount`; a draft owes nothing.
            'outstandingBalance' => (float) Invoice::where('client_id', $client->id)
                ->where('status', '!=', Invoice::STATUS_DRAFT)
                ->get()
                ->sum(fn (Invoice $invoice) => $invoice->outstanding()),
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
                ])->values()->all(),
            'createdAt' => $client->created_at?->toISOString(),
        ];
    }

    /**
     * The same rules whether the client is being added or corrected — a
     * straight mirror of web's own `ClientController::validated()`.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Client $client = null, bool $requireCrew = false): array
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
            'website' => ['nullable', 'string', 'max:255'],
            // The primary contact's quick email/phone — written onto that contact.
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40', new UsPhoneNumber],
            'notes' => ['nullable', 'string', 'max:2000'],
            'labor_rate' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            // Required when a client is added: who from the crew works for them.
            'member_ids' => $requireCrew ? ['required', 'array', 'min:1'] : ['nullable', 'array'],
            'member_ids.*' => ['integer', CompanyRule::exists('foremen')],
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
            'member_ids.required' => 'Pick at least one member',
            'member_ids.min' => 'Pick at least one member',
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

    /** An untyped optional field is nothing, not an empty string. */
    private function orNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeWebsite(?string $value): ?string
    {
        $trimmed = $this->orNull($value);

        if ($trimmed === null) {
            return null;
        }

        return str_contains($trimmed, '://') ? $trimmed : "https://{$trimmed}";
    }

    /**
     * The quick email/phone fields on the client's form, written onto its
     * primary contact. A client with no contact yet gets one, named after the
     * client itself, the moment either field is filled in.
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
}
