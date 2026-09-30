<?php

namespace App\Services\Company;

use App\Models\CompanyProfile;
use App\Models\Foreman;
use App\Models\Subscription;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationSent;
use App\Notifications\TeamMemberAdded;
use App\Services\TimeTracking\TeamMemberResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Inviting people into a company, and what happens when they accept.
 *
 * Sending an invitation reserves a seat on the plan and emails a link; nothing else is
 * created. Accepting is what makes the account — with the role and team the
 * invitation names — so access exists only for someone who said yes.
 */
class TeamInvitations
{
    public function __construct(private readonly TeamMemberResolver $resolver) {}

    /**
     * Where the company stands on seats: people with an account, invitations still open
     * (each holds a seat), and what is left. A plan with no limit has no `available`.
     *
     * @return array{used: int, pending: int, limit: ?int, available: ?int}
     */
    public function seats(User $user): array
    {
        $limit = config('subscription.plans.'.Subscription::forUser($user)->plan.'.max_users');
        $used = User::query()->where('company_id', $user->company_id)->where('status', User::STATUS_ACTIVE)->count();
        $pending = $this->openInvitations($user->company_id)->count();

        return [
            'used' => $used,
            'pending' => $pending,
            'limit' => $limit,
            'available' => $limit === null ? null : max(0, $limit - $used - $pending),
        ];
    }

    /**
     * Sends an invitation to each row.
     *
     * A row with a password becomes an account straight away, so they can sign in at once; a row
     * without one is emailed a link to choose their own.
     *
     * @param  list<array{name: string, email: string, phone?: ?string, role: string, team_id?: ?int, password?: ?string}>  $rows
     * @return list<TeamInvitation>
     *
     * @throws ValidationException when the plan has fewer seats than there are people to invite
     */
    public function send(User $inviter, array $rows): array
    {
        $available = $this->seats($inviter)['available'];

        if ($available !== null && count($rows) > $available) {
            throw ValidationException::withMessages([
                'invitations' => $available === 0
                    ? 'Your plan has no seats left. Upgrade your plan to invite more people.'
                    : "Your plan has {$available} ".Str::plural('seat', $available).' left, and you are inviting '.count($rows).'. Remove '.(count($rows) - $available).' or upgrade your plan.',
            ]);
        }

        $company = CompanyProfile::query()->findOrFail($inviter->company_id);

        return DB::transaction(function () use ($inviter, $rows, $company) {
            $sent = [];

            foreach ($rows as $row) {
                [$token, $hash] = TeamInvitation::newToken();

                $invitation = new TeamInvitation([
                    'company_id' => $company->id,
                    'invited_by' => $inviter->id,
                    'name' => trim($row['name']),
                    'email' => Str::lower(trim($row['email'])),
                    'phone' => filled($row['phone'] ?? null) ? $row['phone'] : null,
                    'role' => $row['role'],
                    'team_id' => $row['team_id'] ?? null,
                ]);
                $invitation->forceFill(['token_hash' => $hash, 'sent_at' => now(), 'expires_at' => now()->addDays(TeamInvitation::VALID_DAYS)])->save();

                if (filled($row['password'] ?? null)) {
                    // The manager chose their password: the account exists now, and they are told so.
                    $this->accept($invitation, $row['password']);
                    Notification::route('mail', [$invitation->email => $invitation->name])
                        ->notify(new TeamMemberAdded($invitation->refresh(), $company->name, $inviter->name));
                } else {
                    $this->mail($invitation, $token, $company, $inviter);
                }

                $sent[] = $invitation->refresh();
            }

            return $sent;
        });
    }

    /** Sends the invitation again, on a fresh link and a fresh week. */
    public function resend(TeamInvitation $invitation, User $inviter): void
    {
        [$token, $hash] = TeamInvitation::newToken();

        $invitation->forceFill(['token_hash' => $hash, 'sent_at' => now(), 'expires_at' => now()->addDays(TeamInvitation::VALID_DAYS)])->save();

        $this->mail($invitation, $token, CompanyProfile::query()->findOrFail($invitation->company_id), $inviter);
    }

    /** Withdraws it: the link stops working and the seat is free again. */
    public function cancel(TeamInvitation $invitation): void
    {
        // `status` is deliberately not mass-assignable, so it is set directly.
        $invitation->forceFill(['status' => TeamInvitation::STATUS_CANCELLED])->save();
    }

    /**
     * Creates the account for someone who accepted: the role and team the invitation names,
     * in the company that invited them.
     *
     * @throws ValidationException when the email has since been taken, or the plan filled up
     */
    public function accept(TeamInvitation $invitation, string $password): User
    {
        return DB::transaction(function () use ($invitation, $password) {
            $invitation = TeamInvitation::withoutGlobalScopes()->lockForUpdate()->findOrFail($invitation->id);

            if (! $invitation->isPending()) {
                throw ValidationException::withMessages(['invitation' => 'This invitation is no longer open.']);
            }

            if (User::query()->where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages(['email' => 'Someone already has an account with this email. Sign in instead.']);
            }

            $company = CompanyProfile::query()->findOrFail($invitation->company_id);
            $limit = config('subscription.plans.'.Subscription::forUser($company->user)->plan.'.max_users');
            $used = User::query()->where('company_id', $company->id)->where('status', User::STATUS_ACTIVE)->count();

            if ($limit !== null && $used >= $limit) {
                throw ValidationException::withMessages(['invitation' => 'This company\'s plan has no seat left. Ask them to upgrade their plan.']);
            }

            $user = User::create([
                'name' => $invitation->name,
                'email' => $invitation->email,
                'password' => Hash::make($password),
                'role' => $invitation->role,
                'phone' => $invitation->phone,
            ]);
            $user->company_id = $company->id;
            $user->save();

            // Crew go on the register too, on the team they were invited to.
            if ($invitation->role !== 'Project Manager') {
                $foreman = new Foreman([
                    'name' => $invitation->name,
                    'initials' => $user->initials,
                    'role' => Str::lower($invitation->role),
                    'team_id' => $invitation->team_id,
                    'phone' => $invitation->phone,
                    'email' => $invitation->email,
                    'started_on' => now()->toDateString(),
                ]);
                $foreman->company_id = $company->id;
                $foreman->user_id = $user->id;
                $foreman->save();

                $this->resolver->resolveFor($user)->update(['team_id' => $invitation->team_id]);
            }

            $invitation->forceFill([
                'status' => TeamInvitation::STATUS_ACCEPTED,
                'accepted_at' => now(),
                'user_id' => $user->id,
            ])->save();

            return $user;
        });
    }

    private function mail(TeamInvitation $invitation, string $token, CompanyProfile $company, User $inviter): void
    {
        Notification::route('mail', [$invitation->email => $invitation->name])
            ->notify(new TeamInvitationSent($invitation, $token, $company->name, $inviter->name));
    }

    /** Invitations that still hold a seat: sent, not answered, not lapsed. */
    private function openInvitations(?int $companyId)
    {
        return TeamInvitation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', TeamInvitation::STATUS_PENDING)
            ->where('expires_at', '>', now());
    }
}
