<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
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
            'password' => 'hashed',
        ];
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

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<Upload, $this> */
    public function uploads(): HasMany
    {
        return $this->hasMany(Upload::class);
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
