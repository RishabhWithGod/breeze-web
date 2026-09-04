<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Estimate extends Model
{
    use SoftDeletes;

    public const STATUSES = ['draft', 'sent', 'approved', 'rejected'];

    /** Sort keys accepted by `scopeSorted`, mirrored by ESTIMATE_SORT_OPTIONS. */
    public const SORTS = ['date-desc', 'date-asc', 'amount-desc', 'amount-asc', 'number-asc'];

    protected $fillable = [
        'job_id',
        'project_id',
        /*
         * Who the estimate is for. Left out of this list until now, which is
         * why picking a client on the edit screen saved nothing at all — and
         * why every takeoff-built estimate came out with none.
         */
        'client_id',
        'ai_result_id',
        'number',
        'client',
        'project',
        'issued_on',
        'amount',
        'status',
        'converted_project_id',
        'converted_at',
        'material_total',
        'labor_total',
        'equipment_total',
        'subtotal',
        'markup_pct',
        'markup_total',
        'tax_pct',
        'tax_total',
        'grand_total',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'amount' => 'decimal:2',
            'material_total' => 'decimal:2',
            'labor_total' => 'decimal:2',
            'equipment_total' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'markup_pct' => 'decimal:2',
            'markup_total' => 'decimal:2',
            'tax_pct' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'converted_at' => 'datetime',
        ];
    }

    /**
     * The status an estimate holds once a job has been raised against it.
     *
     * Raising the job *is* the act of accepting the estimate — the work is
     * going ahead and someone is being sent to do it. Leaving it "draft" said
     * the opposite on every screen that showed it.
     */
    public const STATUS_FOR_A_LIVE_JOB = 'approved';

    /**
     * The status a new estimate should carry, given whether it already has a
     * job behind it.
     *
     * One rule in one place: an estimate is raised from four different points
     * in the flow, and they were each deciding this for themselves.
     */
    public static function statusFor(?Job $job): string
    {
        return $job === null ? 'draft' : self::STATUS_FOR_A_LIVE_JOB;
    }

    /** @return BelongsTo<Job, $this> */
    /**
     * The client an estimate is for is the client of the project it is on.
     *
     * A project belongs to exactly one client, so this is derived rather than
     * asked for twice — and every place that raises an estimate (the takeoff,
     * a job, the create form) sets the project, not the client. Filling it here
     * means none of them can leave it blank, and moving an estimate to another
     * project takes its client with it.
     */
    protected static function booted(): void
    {
        static::saving(function (self $estimate): void {
            if ($estimate->project_id === null) {
                return;
            }

            if ($estimate->client_id !== null && ! $estimate->isDirty('project_id')) {
                return;
            }

            $estimate->client_id = Project::whereKey($estimate->project_id)->value('client_id');
        });
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** @return HasMany<EstimateItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(EstimateItem::class)->orderBy('category')->orderBy('position');
    }

    /** Invoices raised from this estimate, when Billing has converted it. */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class)->latest('updated_at');
    }

    /**
     * The client this estimate is for — and, when it came from one, the takeoff
     * it was generated from. Clients are projects, so both are the same record.
     *
     * Named `takeoffProject` because `project` is already a column on this table
     * (the client-name snapshot), and an attribute always shadows a relation.
     */
    public function takeoffProject(): BelongsTo
    {
        // The column must be named: `belongsTo` would otherwise infer
        // `takeoff_project_id` from this method's name.
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function clientRecord(): BelongsTo
    {
        // Named for what it is: `client` is the name snapshot column.
        return $this->belongsTo(Client::class, 'client_id');
    }

    /** @return BelongsTo<AiResult, $this> */
    public function aiResult(): BelongsTo
    {
        return $this->belongsTo(AiResult::class);
    }

    /**
     * Re-sums the line items and re-applies markup and tax.
     *
     * `amount` stays the headline figure the estimates list sorts on, so it
     * always mirrors the grand total.
     */
    public function recalculateTotals(): void
    {
        $items = $this->items()->get();
        $byCategory = fn (string ...$categories) => (float) $items
            ->whereIn('category', $categories)
            ->sum('total');

        $material = $byCategory(EstimateItem::CATEGORY_MATERIAL, EstimateItem::CATEGORY_FIXTURE);
        $labor = $byCategory(EstimateItem::CATEGORY_LABOR);
        $equipment = $byCategory(EstimateItem::CATEGORY_EQUIPMENT);

        $subtotal = round($material + $labor + $equipment, 2);
        $markup = round($subtotal * ((float) $this->markup_pct / 100), 2);
        $tax = round(($subtotal + $markup) * ((float) $this->tax_pct / 100), 2);
        $grand = round($subtotal + $markup + $tax, 2);

        $this->update([
            'material_total' => $material,
            'labor_total' => $labor,
            'equipment_total' => $equipment,
            'subtotal' => $subtotal,
            'markup_total' => $markup,
            'tax_total' => $tax,
            'grand_total' => $grand,
            'amount' => $grand,
        ]);
    }

    /** The takeoff project this estimate was converted into, if any. */
    public function convertedProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'converted_project_id');
    }

    public function isConverted(): bool
    {
        return $this->converted_project_id !== null;
    }

    /** Matches an estimate number, client or project name. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($term) {
            $query->where('number', 'like', "%{$term}%")
                ->orWhere('client', 'like', "%{$term}%")
                ->orWhere('project', 'like', "%{$term}%");
        });
    }

    /** Inclusive date window; either bound may be omitted. */
    public function scopeIssuedBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $query) => $query->whereDate('issued_on', '>=', $from))
            ->when($to, fn (Builder $query) => $query->whereDate('issued_on', '<=', $to));
    }

    public function scopeSorted(Builder $query, ?string $sort): Builder
    {
        return match ($sort) {
            'date-asc' => $query->orderBy('issued_on')->orderBy('id'),
            'amount-desc' => $query->orderByDesc('amount'),
            'amount-asc' => $query->orderBy('amount'),
            'number-asc' => $query->orderBy('number'),
            default => $query->orderByDesc('issued_on')->orderByDesc('id'),
        };
    }

    /**
     * Next reference in the EST-#### series, continuing past soft-deleted rows
     * so a restored estimate can never collide with a newly created one.
     */
    public static function nextNumber(): string
    {
        /*
         * `INTEGER` is SQLite's spelling of this cast; MySQL rejects it outright and
         * wants `SIGNED`. The series is the one thing that must never depend on which
         * driver is underneath, so the type is chosen rather than assumed.
         */
        $integerType = match (static::query()->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => 'SIGNED',
            default => 'INTEGER',
        };

        $highest = (int) static::withTrashed()
            ->selectRaw("MAX(CAST(SUBSTR(number, 5) AS {$integerType})) AS seq")
            ->value('seq');

        return 'EST-'.max($highest + 1, 1001);
    }
}
