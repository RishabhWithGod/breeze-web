<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Someone a company has invited to join it, as a particular role on a particular
 * team. They have no access until they accept: accepting is what creates their
 * account, with the role and team the invitation names.
 */
class TeamInvitation extends Model
{
    use BelongsToCompany;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_CANCELLED = 'cancelled';

    /** How long an invitation stays open. */
    public const VALID_DAYS = 7;

    /** The roles a person can be invited as. */
    public const ROLES = ['Project Manager', 'Foreman', 'Journeyman', 'Apprentice'];

    protected $fillable = ['company_id', 'invited_by', 'name', 'email', 'phone', 'role', 'team_id'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** A fresh random token, and the hash that is kept in its place. @return array{0: string, 1: string} */
    public static function newToken(): array
    {
        $token = Str::random(48);

        return [$token, hash('sha256', $token)];
    }

    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING && ! $this->hasLapsed();
    }

    /** Sent and never answered, and past its date. */
    public function hasLapsed(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isPast();
    }

    /** What the screen calls it: pending, accepted, cancelled or expired. */
    public function label(): string
    {
        return $this->hasLapsed() ? 'expired' : $this->status;
    }
}
