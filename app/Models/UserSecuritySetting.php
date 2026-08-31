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

    protected $fillable = [
        'user_id', 'two_factor_enabled', 'two_factor_method', 'two_factor_confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'two_factor_enabled' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function forUser(User $user): self
    {
        return static::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['two_factor_enabled' => false, 'two_factor_method' => self::METHOD_EMAIL],
        );
    }
}
