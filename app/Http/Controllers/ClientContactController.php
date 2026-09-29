<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientContact;
use App\Rules\UsPhoneNumber;
use App\Support\UsPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Adding a person to a client's own book without leaving the screen that
 * needed one.
 *
 * A client is rarely one person — an owner, a project manager, whoever
 * actually answers the phone — so this is a list rather than a single
 * email/phone pair on the client itself.
 */
class ClientContactController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        abort_unless($client->user_id === $request->user()->id, 403);

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

        return back()->with('success', "“{$contact->name}” was added to {$client->name}.");
    }

    public function update(Request $request, Client $client, ClientContact $contact): RedirectResponse
    {
        abort_unless($client->user_id === $request->user()->id, 403);
        abort_unless($contact->client_id === $client->id, 404);

        $data = $this->validated($request);

        $contact->update([
            'name' => trim($data['name']),
            'role' => $this->orNull($data['role'] ?? null),
            'email' => $this->orNull($data['email'] ?? null),
            'phone' => UsPhone::format($this->orNull($data['phone'] ?? null)),
        ]);

        return back()->with('success', "“{$contact->name}” was updated.");
    }

    /**
     * Removing a person from the book.
     *
     * Removing the primary promotes the next one, because "who a form
     * defaults to" has to be someone who is still on the book.
     */
    public function destroy(Request $request, Client $client, ClientContact $contact): RedirectResponse
    {
        abort_unless($client->user_id === $request->user()->id, 403);
        abort_unless($contact->client_id === $client->id, 404);

        $name = $contact->name;
        $wasPrimary = $contact->is_primary;

        $contact->delete();

        if ($wasPrimary) {
            $client->contacts()->oldest('position')->oldest('id')->first()
                ?->update(['is_primary' => true]);
        }

        return back()->with('warning', "“{$name}” was removed from {$client->name}.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            /*
             * What they do at the client — "Owner", "Project Manager". Free
             * text: this is their relationship to the client, not a role this
             * app assigns.
             */
            'role' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40', new UsPhoneNumber],
        ], [
            'name.required' => 'Enter their name.',
            'email.email' => 'That does not look like an email address',
        ]);
    }

    /** An untyped optional field is nothing, not an empty string. */
    private function orNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
