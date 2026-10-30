<?php

namespace App\Models;

use App\Services\JobScope\ChangeSource;
use App\Services\JobScope\JobScopeLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One real, manually-recorded actual cost against a job — material,
 * equipment, or anything else that is not labor (labor's actual cost comes
 * from approved time entries instead; see `JobCostSummary`).
 */
class JobCostEntry extends Model
{
    public const CATEGORY_MATERIAL = 'material';

    public const CATEGORY_EQUIPMENT = 'equipment';

    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_MATERIAL,
        self::CATEGORY_EQUIPMENT,
        self::CATEGORY_OTHER,
    ];

    protected $fillable = [
        'job_id',
        'category',
        'description',
        'quantity',
        'unit_cost',
        'amount',
        'incurred_on',
        'recorded_by',
        'notes',
        'source',
        'invoice_id',
        'invoice_item_id',
    ];

    protected static function booted(): void
    {
        // A cost logged (or withdrawn) changes what the job came to — remembered, broadcast, and billed.
        $record = function (self $entry, string $verb): void {
            $job = Job::find($entry->job_id);
            if ($job === null) {
                return;
            }

            $category = ucfirst($entry->category);
            $amount = $verb === 'withdrawn' ? -(float) $entry->amount : (float) $entry->amount;
            $fromInvoice = $entry->source === 'invoice';

            app(JobScopeLog::class)->record($job, JobScopeChange::KIND_COST_ENTRY, "{$category} cost {$verb}: ".mb_substr((string) $entry->description, 0, 120), [
                'amount' => $amount,
                'source' => $fromInvoice ? ChangeSource::INVOICE : ChangeSource::current(),
                'except_invoice_id' => $fromInvoice ? $entry->invoice_id : null,
            ]);
        };

        static::created(fn (self $entry) => $record($entry, 'logged'));
        static::updated(fn (self $entry) => $entry->wasChanged('amount') ? $record($entry, 'adjusted') : null);
        static::deleted(fn (self $entry) => $record($entry, 'withdrawn'));
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'amount' => 'decimal:2',
            'incurred_on' => 'date',
        ];
    }

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeIncurredBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $query) => $query->whereDate('incurred_on', '>=', $from))
            ->when($to, fn (Builder $query) => $query->whereDate('incurred_on', '<=', $to));
    }
}
