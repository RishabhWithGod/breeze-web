<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per user. `two_factor_enabled` only ever flips to true from
 * `TwoFactorService::confirmEnable()` after a real OTP is verified.
 */
class UserSecuritySetting extends Model
{
    public const METHOD_EMAIL = 'email';

    public const METHOD_SMS = 'sms';

    /** Where someone signs in. Each has its own two-factor switch. */
    public const CHANNEL_WEB = 'web';

    public const CHANNEL_APP = 'app';

    protected $fillable = [
        'user_id', 'two_factor_enabled', 'two_factor_method', 'two_factor_confirmed_at',
        'app_two_factor_enabled', 'app_two_factor_confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'two_factor_enabled' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
            'app_two_factor_enabled' => 'boolean',
            'app_two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether signing in through [$channel] asks for a code. */
    public function twoFactorEnabledFor(string $channel): bool
    {
        return $channel === self::CHANNEL_APP ? $this->app_two_factor_enabled : $this->two_factor_enabled;
    }

    public static function forUser(User $user): self
    {
        return static::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['two_factor_enabled' => false, 'app_two_factor_enabled' => false, 'two_factor_method' => self::METHOD_EMAIL],
        );
    }
}
