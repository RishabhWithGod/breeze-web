<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The real, immutable Security Audit Log entry. No update/delete route is
 * ever exposed for this table — it is written only by `SecurityEventLogger`.
 */
class SecurityEvent extends Model
{
    public const LOGIN_SUCCESS = 'login_success';

    public const LOGIN_FAILED = 'login_failed';

    public const NEW_DEVICE_LOGIN = 'new_device_login';

    public const PASSWORD_CHANGED = 'password_changed';

    public const PROFILE_UPDATED = 'profile_updated';

    public const TWO_FACTOR_ENABLED = 'two_factor_enabled';

    public const TWO_FACTOR_DISABLED = 'two_factor_disabled';

    public const TWO_FACTOR_VERIFICATION_FAILED = 'two_factor_verification_failed';

    public const RECOVERY_CODES_GENERATED = 'recovery_codes_generated';

    public const RECOVERY_CODE_USED = 'recovery_code_used';

    public const AUTH_METHOD_CHANGED = 'auth_method_changed';

    public const NOTIFICATION_PREFERENCE_CHANGED = 'notification_preference_changed';

    public const LABELS = [
        self::LOGIN_SUCCESS => 'Successful login',
        self::LOGIN_FAILED => 'Failed login attempt',
        self::NEW_DEVICE_LOGIN => 'New device login',
        self::PASSWORD_CHANGED => 'Password changed',
        self::PROFILE_UPDATED => 'Profile updated',
        self::TWO_FACTOR_ENABLED => '2FA enabled',
        self::TWO_FACTOR_DISABLED => '2FA disabled',
        self::TWO_FACTOR_VERIFICATION_FAILED => '2FA verification failed',
        self::RECOVERY_CODES_GENERATED => 'Recovery codes regenerated',
        self::RECOVERY_CODE_USED => 'Recovery code used',
        self::AUTH_METHOD_CHANGED => 'Authentication method changed',
        self::NOTIFICATION_PREFERENCE_CHANGED => 'Notification preference changed',
    ];

    protected $fillable = [
        'user_id', 'type', 'description', 'ip_address', 'user_agent', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return self::LABELS[$this->type] ?? $this->type;
    }
}
