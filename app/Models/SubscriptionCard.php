<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The card a subscription is paid with: brand, last four and expiry, and where
 * the bill goes. The number and CVV are never stored — there is no column for
 * either.
 */
class SubscriptionCard extends Model
{
    protected $fillable = [
        'user_id', 'cardholder_name', 'brand', 'last_four', 'exp_month', 'exp_year',
        'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** "Visa ending in 4242" */
    public function label(): string
    {
        return "{$this->brand} ending in {$this->last_four}";
    }

    public function expiry(): string
    {
        return sprintf('%02d/%d', $this->exp_month, $this->exp_year);
    }
}
