<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The company's invoice-sending and default-terms preferences. One row,
 * always — mirrors `TimeTrackingSetting`'s singleton convention exactly.
 */
class BillingSetting extends Model
{
    protected $fillable = [
        'auto_send_invoices',
        'include_payment_instructions',
        'send_payment_reminders',
        'apply_late_fees_automatically',
        'default_payment_terms',
        'default_currency',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'auto_send_invoices' => 'boolean',
            'include_payment_instructions' => 'boolean',
            'send_payment_reminders' => 'boolean',
            'apply_late_fees_automatically' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([], config('payments.defaults'));
    }
}
