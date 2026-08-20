<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

/**
 * Who may do what to an invoice.
 *
 * Built on `users.role`, the same free-text column `TimeEntryPolicy` and
 * `JobSchedulePolicy` already match case-insensitively. Anyone signed in may
 * view invoices — the same openness Estimates and Jobs already have, neither
 * of which has a policy restricting viewing either. Only a Project
 * Manager/Admin/Owner may create, change, send, mark paid, or delete one:
 * this is money moving, not a schedule.
 */
class InvoicePolicy
{
    /** Roles that may create, edit, send, collect on or delete an invoice. */
    private const MANAGERS = ['project manager', 'admin', 'owner'];

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MANAGERS);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $this->holds($user, self::MANAGERS) && $invoice->isEditable();
    }

    /** A paid invoice is history — it may never be deleted, only a draft or sent one. */
    public function delete(User $user, Invoice $invoice): bool
    {
        return $this->holds($user, self::MANAGERS) && $invoice->isEditable();
    }

    public function send(User $user, Invoice $invoice): bool
    {
        return $this->holds($user, self::MANAGERS) && $invoice->status === Invoice::STATUS_DRAFT;
    }

    public function markPaid(User $user, Invoice $invoice): bool
    {
        return $this->holds($user, self::MANAGERS) && $invoice->status === Invoice::STATUS_SENT;
    }

    /** Derived so the client can hide what it cannot do, rather than fail on submit. */
    public function abilities(User $user): array
    {
        return [
            'create' => $this->create($user),
            'manage' => $this->holds($user, self::MANAGERS),
        ];
    }

    /* ------------------------------------------------------------- internals */

    /** @param  list<string>  $roles */
    private function holds(User $user, array $roles): bool
    {
        return in_array(mb_strtolower(trim((string) $user->role)), $roles, true);
    }
}
