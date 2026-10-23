<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientContact;
use App\Rules\UsPhoneNumber;
use App\Support\Ownership;
use App\Support\UsPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A client's own book of people, as the mobile app sees it — web's
 * `ClientContactController`. A client is rarely one person (an owner, a
 * project manager, whoever answers the phone), so this is a list; the first
 * one added is the primary, and removing the primary promotes the next.
 */
class ClientContactController extends Controller
{
    use ApiResponses;

    public function store(Request $request, Client $client): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $client->user_id), 403);

        $data = $this->validated($request);

        // The first person on the book is who a form defaults to.
        $isFirst = ! $client->contacts()->exists();

        $contact = $client->contacts()->create([
            'name' => trim($data['name']),
            'role' => $this->orNull($data['role'] ?? null),
            'email' => $this->orNull($data['email'] ?? null),
            'phone' => UsPhone::format($this->orNull($data['phone'] ?? null)),
            'is_primary' => $isFirst,
            'position' => (int) $client->contacts()->max('position') + ($isFirst ? 0 : 1),
        ]);

        return $this->created($this->present($contact), "\"{$contact->name}\" was added to {$client->name}.");
    }

    public function update(Request $request, Client $client, ClientContact $contact): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $client->user_id), 403);
        abort_unless($contact->client_id === $client->id, 404);

        $data = $this->validated($request);

        $contact->update([
            'name' => trim($data['name']),
            'role' => $this->orNull($data['role'] ?? null),
            'email' => $this->orNull($data['email'] ?? null),
            'phone' => UsPhone::format($this->orNull($data['phone'] ?? null)),
        ]);

        return $this->ok($this->present($contact->fresh()), "\"{$contact->name}\" was updated.");
    }

    public function destroy(Request $request, Client $client, ClientContact $contact): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $client->user_id), 403);
        abort_unless($contact->client_id === $client->id, 404);

        $name = $contact->name;
        $wasPrimary = $contact->is_primary;

        $contact->delete();

        if ($wasPrimary) {
            $client->contacts()->oldest('position')->oldest('id')->first()
                ?->update(['is_primary' => true]);
        }

        return $this->ok(null, "\"{$name}\" was removed from {$client->name}.");
    }

    /** @return array<string, mixed> */
    private function present(ClientContact $contact): array
    {
        return [
            'id' => $contact->id,
            'name' => $contact->name,
            'role' => $contact->role,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'isPrimary' => (bool) $contact->is_primary,
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            // What they do at the client — free text, not a role this app assigns.
            'role' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40', new UsPhoneNumber],
        ], [
            'name.required' => 'Enter their name.',
            'email.email' => 'That does not look like an email address',
        ]);
    }

    private function orNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
