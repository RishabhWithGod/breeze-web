<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

/**
 * Who may do what to a document.
 *
 * Every document sits on a project, and a project has one owner — so on top
 * of `users.role` (the same free-text column `InvoicePolicy` and
 * `JobCostingPolicy` already match case-insensitively), a "manager" bypass
 * only ever applies to a manager's *own* project. Any signed-in user may
 * view, upload and favorite a document on a project that is theirs — the
 * same openness Jobs/Estimates/Invoices have within their own data. A
 * document marked `visibility = private` is the exception: only its
 * uploader, the project's manager, or someone it was shared with may see it.
 * Editing metadata, deleting, archiving/restoring, managing versions and
 * sharing are restricted to the uploader or the project's manager, since
 * those actions affect a file someone else may be relying on.
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
                || $this->manages($user, $document)
                || $document->isSharedWith($user);
        }

        return $this->onOwnProject($user, $document);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Document $document): bool
    {
        return $this->owns($user, $document) || $this->manages($user, $document);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->owns($user, $document) || $this->manages($user, $document);
    }

    public function archive(User $user, Document $document): bool
    {
        return $this->owns($user, $document) || $this->manages($user, $document);
    }

    public function manageVersions(User $user, Document $document): bool
    {
        return $this->owns($user, $document) || $this->manages($user, $document);
    }

    public function share(User $user, Document $document): bool
    {
        return $this->owns($user, $document) || $this->manages($user, $document);
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

    /** A manager's role only reaches documents on their own project. */
    private function manages(User $user, Document $document): bool
    {
        return $this->holds($user, self::MANAGERS) && $this->onOwnProject($user, $document);
    }

    private function onOwnProject(User $user, Document $document): bool
    {
        return $document->project?->user_id === $user->id;
    }

    /** @param  list<string>  $roles */
    private function holds(User $user, array $roles): bool
    {
        return in_array(mb_strtolower(trim((string) $user->role)), $roles, true);
    }
}
