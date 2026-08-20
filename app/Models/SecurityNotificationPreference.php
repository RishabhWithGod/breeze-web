<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per (user, event type). Both channels default false — real, per-user preferences. */
class SecurityNotificationPreference extends Model
{
    public const LOGIN_ATTEMPT = 'login_attempt';

    public const PASSWORD_CHANGED = 'password_changed';

    public const PROFILE_UPDATED = 'profile_updated';

    public const NEW_DEVICE_LOGIN = 'new_device_login';

    public const EVENT_TYPES = [
        self::LOGIN_ATTEMPT,
        self::PASSWORD_CHANGED,
        self::PROFILE_UPDATED,
        self::NEW_DEVICE_LOGIN,
    ];

    public const LABELS = [
        self::LOGIN_ATTEMPT => 'Login Attempts',
        self::PASSWORD_CHANGED => 'Password Changes',
        self::PROFILE_UPDATED => 'Profile Updates',
        self::NEW_DEVICE_LOGIN => 'New Device Login',
    ];

    protected $fillable = ['user_id', 'event_type', 'sms_enabled', 'email_enabled'];

    protected function casts(): array
    {
        return [
            'sms_enabled' => 'boolean',
            'email_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** All four event rows for the user, creating any missing ones with both channels off. */
    public static function allForUser(User $user): \Illuminate\Support\Collection
    {
        foreach (self::EVENT_TYPES as $type) {
            static::query()->firstOrCreate(['user_id' => $user->id, 'event_type' => $type]);
        }

        return static::query()->where('user_id', $user->id)->orderByRaw(
            'FIELD(event_type, "'.implode('","', self::EVENT_TYPES).'")'
        )->get();
    }
}
