<?php

namespace App\Models;

use App\Models\Relations\CompanyHasMany;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_INACTIVE = 'inactive';

    public const SOURCE_WEB = 'web';

    public const SOURCE_MOBILE = 'mobile';

    /**
     * The attributes that are mass assignable.
     *
     * `status`/`approved_at`/`approved_by`/`registration_source` are
     * deliberately absent — only `AuthController::register()` and
     * `TechnicianController` ever set them, never a bare mass-assignment.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'takeoff_flow_project_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'needs_company_setup' => 'boolean',
            'needs_terms_acceptance' => 'boolean',
            'needs_payment_setup' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isFromMobile(): bool
    {
        return $this->registration_source === self::SOURCE_MOBILE;
    }

    protected static function booted(): void
    {
        // Initials are a display concern, so they are derived rather than typed.
        static::saving(function (User $user) {
            if ($user->isDirty('name') || blank($user->initials)) {
                $user->initials = static::initialsFor($user->name);
            }
        });
    }

    /** "Alex Morgan" → "AM" */
    public static function initialsFor(string $name): string
    {
        return Str::of($name)
            ->squish()
            ->explode(' ')
            ->take(2)
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');
    }

    /** @return HasOne<CompanyProfile, $this> */
    public function company(): HasOne
    {
        return $this->hasOne(CompanyProfile::class);
    }

    /** @return HasMany<TermsAcceptance, $this> */
    public function termsAcceptances(): HasMany
    {
        return $this->hasMany(TermsAcceptance::class);
    }

    /**
     * A `hasMany` that reads the whole company's rows — see {@see CompanyHasMany}.
     *
     * @template TRelated of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TRelated>  $related
     * @return CompanyHasMany<TRelated, $this>
     */
    private function companyHasMany(string $related): CompanyHasMany
    {
        $instance = $this->newRelatedInstance($related);

        return new CompanyHasMany($instance->newQuery(), $this, $instance->qualifyColumn('user_id'), $this->getKeyName());
    }

    /** @return HasMany<Client, $this> */
    public function clients(): HasMany
    {
        return $this->companyHasMany(Client::class)->orderBy('name');
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->companyHasMany(Project::class);
    }

    /** @return HasMany<Job, $this> */
    public function jobs(): HasMany
    {
        return $this->companyHasMany(Job::class);
    }

    /** @return HasMany<Estimate, $this> */
    public function estimates(): HasMany
    {
        return $this->companyHasMany(Estimate::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->companyHasMany(Invoice::class);
    }

    /** @return HasMany<Upload, $this> */
    public function uploads(): HasMany
    {
        return $this->companyHasMany(Upload::class);
    }

    /** @return HasMany<AppNotification, $this> */
    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    /**
     * The crew record this account signs in as, when one is linked.
     *
     * @return HasOne<TeamMember, $this>
     */
    public function teamMember(): HasOne
    {
        return $this->hasOne(TeamMember::class);
    }

    /** The manager who approved this account, when it went through mobile signup. */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The crew register entry this account was synced into, once a manager
     * has given it both a team and a role — the same `foremen` row every
     * web-created crew member has, so an approved technician shows up on
     * their team's roster rather than staying in a separate list forever.
     *
     * @return HasOne<Foreman, $this>
     */
    public function foreman(): HasOne
    {
        return $this->hasOne(Foreman::class);
    }

    /**
     * Whether this account carries a foreman's authority on the mobile app:
     * the crew register's Foreman, or a manager-level account (which has no
     * register row at all). The mobile Foreman screens — whole-job task board,
     * crew time, approving a crew member's work, assigning apprentices,
     * closing a job out — are exactly what a manager needs too, so both read
     * true here rather than each gate asking `foreman?->role` and silently
     * locking managers out.
     */
    public function hasForemanAuthority(): bool
    {
        if ($this->foreman?->role === Foreman::ROLE_FOREMAN) {
            return true;
        }

        return in_array(
            mb_strtolower(trim((string) $this->role)),
            ['project manager', 'manager', 'admin', 'owner'],
            true,
        );
    }

    /** @return HasMany<TimeEntry, $this> */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /** @return HasMany<TimerSession, $this> */
    public function timerSessions(): HasMany
    {
        return $this->hasMany(TimerSession::class);
    }

    /** @return HasOne<UserSecuritySetting, $this> */
    public function securitySetting(): HasOne
    {
        return $this->hasOne(UserSecuritySetting::class);
    }

    /** @return HasMany<TwoFactorRecoveryCode, $this> */
    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(TwoFactorRecoveryCode::class);
    }

    /** @return HasMany<SecurityEvent, $this> */
    public function securityEvents(): HasMany
    {
        return $this->hasMany(SecurityEvent::class);
    }

    /** @return HasMany<UserDevice, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    /** @return HasMany<SecurityNotificationPreference, $this> */
    public function securityNotificationPreferences(): HasMany
    {
        return $this->hasMany(SecurityNotificationPreference::class);
    }

    /** @return HasMany<BreezeBucksTransaction, $this> */
    public function breezeBucksTransactions(): HasMany
    {
        return $this->hasMany(BreezeBucksTransaction::class);
    }

    /** @return HasMany<BreezeBucksRedemption, $this> */
    public function breezeBucksRedemptions(): HasMany
    {
        return $this->hasMany(BreezeBucksRedemption::class);
    }

    /** A masked phone number for display — the raw value is never sent to the client otherwise. */
    public function maskedPhone(): ?string
    {
        if (blank($this->phone)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $this->phone) ?? '';
        $last = substr($digits, -4);

        return str_repeat('•', max(0, strlen($digits) - 4)).$last;
    }

    /** "alex@breeze.com" -> "a•••@breeze.com" */
    public function maskedEmail(): string
    {
        [$local, $domain] = array_pad(explode('@', $this->email, 2), 2, '');

        $masked = strlen($local) > 1
            ? $local[0].str_repeat('•', max(3, strlen($local) - 1))
            : $local.'•••';

        return "{$masked}@{$domain}";
    }
}
