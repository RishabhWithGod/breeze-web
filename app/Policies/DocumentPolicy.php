<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

/**
 * Who may do what to a document.
 *
 * Built on `users.role`, the same free-text column `InvoicePolicy` and
 * `JobCostingPolicy` already match case-insensitively. Any signed-in user may
 * view, upload and favorite — the same openness Jobs/Estimates/Invoices have.
 * A document marked `visibility = private` is the exception: only its
 * uploader and a manager may see it at all. Editing metadata, deleting,
 * archiving/restoring, managing versions and sharing are restricted to the
 * uploader or a manager, since those actions affect a file someone else may
 * be relying on.
 */
class DocumentPolicy
{
    private const MANAGERS = ['project manager', 'admin', 'owner'];

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Document $document): bool
    {
        if ($document->visibility === Document::VISIBILITY_PRIVATE) {
            return $document->uploaded_by === $user->id
                || $this->holds($user, self::MANAGERS)
                || $document->isSharedWith($user);
        }

        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Document $document): bool
    {
        return $this->owns($user, $document) || $this->holds($user, self::MANAGERS);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->owns($user, $document) || $this->holds($user, self::MANAGERS);
    }

    public function archive(User $user, Document $document): bool
    {
        return $this->owns($user, $document) || $this->holds($user, self::MANAGERS);
    }

    public function manageVersions(User $user, Document $document): bool
    {
        return $this->owns($user, $document) || $this->holds($user, self::MANAGERS);
    }

    public function share(User $user, Document $document): bool
    {
        return $this->owns($user, $document) || $this->holds($user, self::MANAGERS);
    }

    /** Derived so the client can hide what it cannot do, rather than fail on submit. */
    public function abilities(User $user): array
    {
        return [
            'createFolder' => true,
            'manage' => $this->holds($user, self::MANAGERS),
        ];
    }

    /* ------------------------------------------------------------- internals */

    private function owns(User $user, Document $document): bool
    {
        return $document->uploaded_by === $user->id;
    }

    /** @param  list<string>  $roles */
    private function holds(User $user, array $roles): bool
    {
        return in_array(mb_strtolower(trim((string) $user->role)), $roles, true);
    }
}
