<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class PaymentMethod extends Model
{
    protected $fillable = [
        'payment_processor_id', 'brand', 'last_four', 'exp_month', 'exp_year',
        'external_id', 'is_default', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'exp_month' => 'integer',
            'exp_year' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<PaymentProcessor, $this> */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(PaymentProcessor::class, 'payment_processor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

    public function isExpired(): bool
    {
        $lastDayOfExpiryMonth = Carbon::create($this->exp_year, $this->exp_month, 1)->endOfMonth();

        return now()->greaterThan($lastDayOfExpiryMonth);
    }
}
