<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentProcessor extends Model
{
    public const STRIPE = 'stripe';

    public const PAYPAL = 'paypal';

    public const SQUARE = 'square';

    public const KEYS = [self::STRIPE, self::PAYPAL, self::SQUARE];

    public const DISPLAY_NAMES = [
        self::STRIPE => 'Stripe',
        self::PAYPAL => 'PayPal',
        self::SQUARE => 'Square',
    ];

    public const STATUS_NOT_CONNECTED = 'not_connected';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_LIMITED = 'limited';

    public const STATUS_ERROR = 'error';

    public const STATUS_PENDING_SETUP = 'pending_setup';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'key', 'display_name', 'status', 'credentials',
        'connected_at', 'last_tested_at', 'last_error', 'connected_by',
    ];

    protected function casts(): array
    {
        return [
            // Laravel's native encrypted-array cast — credentials are never
            // written to or read from the database in plaintext.
            'credentials' => 'encrypted:array',
            'connected_at' => 'datetime',
            'last_tested_at' => 'datetime',
        ];
    }

    protected $hidden = ['credentials'];

    /** @return BelongsTo<User, $this> */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    /** @return HasMany<PaymentMethod, $this> */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    /** @return HasMany<PaymentTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function isConnected(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_LIMITED], true);
    }

    /** The last four of whatever identifying credential the processor uses — never the full value. */
    public function credentialSummary(): ?string
    {
        $credentials = $this->credentials;

        if (blank($credentials)) {
            return null;
        }

        $primary = $credentials['secret_key'] ?? $credentials['client_secret'] ?? $credentials['access_token'] ?? null;

        return $primary ? '••••'.substr((string) $primary, -4) : null;
    }
}
