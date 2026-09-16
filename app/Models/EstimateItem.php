<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One editable line on an estimate. `total` is always quantity × unit cost, kept
 * in sync on save so the estimate totals can be summed in SQL.
 */
class EstimateItem extends Model
{
    public const CATEGORY_MATERIAL = 'material';

    public const CATEGORY_FIXTURE = 'fixture';

    public const CATEGORY_LABOR = 'labor';

    public const CATEGORY_EQUIPMENT = 'equipment';

    public const CATEGORIES = [
        self::CATEGORY_MATERIAL,
        self::CATEGORY_FIXTURE,
        self::CATEGORY_LABOR,
        self::CATEGORY_EQUIPMENT,
    ];

    public const CATEGORY_LABELS = [
        self::CATEGORY_MATERIAL => 'Materials',
        self::CATEGORY_FIXTURE => 'Fixtures',
        self::CATEGORY_LABOR => 'Labor',
        self::CATEGORY_EQUIPMENT => 'Equipment',
    ];

    protected $fillable = [
        'estimate_id',
        'job_task_id',
        'final_symbol_id',
        'category',
        'description',
        'unit',
        'quantity',
        'unit_cost',
        'total',
        'source',
        /*
         * Where the rate came from — 'vendor-rate-list' when this project's
         * own uploaded workbook has priced it before, 'unmatched' when it
         * has never seen it (priced at zero, for the estimator to fill in),
         * and how sure the match was. A guess that reads like a quote is the
         * thing these three columns exist to prevent.
         */
        'pricing_source',
        'price_book_item_id',
        'project_rate_item_id',
        'pricing_confidence',
        'position',
    ];

    protected function casts(): array
    {
        return [
            // Four decimals: conduit and conductor are priced per foot at a
            // fraction of a cent, and rounding the rate misprices the run.
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total' => 'decimal:2',
            'position' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /** Whether the crew has actually done the work this line prices. */
    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            $item->total = round((float) $item->quantity * (float) $item->unit_cost, 2);
        });
    }

    /** @return BelongsTo<Estimate, $this> */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /** The project rate list row this line was quoted at, when it was matched. */
    public function projectRateItem(): BelongsTo
    {
        return $this->belongsTo(ProjectRateItem::class);
    }

    /**
     * The task this line's work was planned into, when it has been.
     *
     * At most one: a line is either scheduled or it is not, and a line counted
     * twice would price work once and plan it twice.
     *
     * @return BelongsTo<JobTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(JobTask::class, 'job_task_id');
    }

    /** This material line's own notes — one per line, not the whole task. */
    public function comments(): HasMany
    {
        return $this->hasMany(EstimateItemComment::class)->oldest('id');
    }

    /** This material line's own photos. */
    public function attachments(): HasMany
    {
        return $this->hasMany(EstimateItemAttachment::class)->latest('id');
    }

    /** @return BelongsTo<FinalSymbol, $this> */
    public function finalSymbol(): BelongsTo
    {
        return $this->belongsTo(FinalSymbol::class);
    }
}
