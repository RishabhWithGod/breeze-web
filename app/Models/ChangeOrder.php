<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Added work, materials or labor on a job after it began: its lines, what they cost and sell for,
 * and where it stands — draft, submitted, then approved or rejected. Approval is what puts the
 * sell amount on the job's billing.
 */
class ChangeOrder extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_APPROVED, self::STATUS_REJECTED];

    public const SOURCE_FIELD = 'field';

    public const SOURCE_OFFICE = 'office';

    public const SOURCES = [self::SOURCE_FIELD, self::SOURCE_OFFICE];

    /** Why added work was raised: the value stored, and what people read. */
    public const REASONS = [
        'scope_change' => 'Scope change',
        'field_condition' => 'Unforeseen field condition',
        'code_requirement' => 'Code or inspection requirement',
        'design_change' => 'Design change',
        'material_issue' => 'Damaged or missing material',
        'other' => 'Other',
    ];

    protected $fillable = ['owner_id', 'job_id', 'number', 'created_by', 'description', 'reason', 'reason_code', 'customer_requested', 'client_key', 'source', 'markup_pct'];

    protected function casts(): array
    {
        return [
            'markup_pct' => 'decimal:2',
            'customer_requested' => 'boolean',
            'labor_hours' => 'decimal:2',
            'labor_cost' => 'decimal:2',
            'material_cost' => 'decimal:2',
            'cost_total' => 'decimal:2',
            'sell_total' => 'decimal:2',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function reasonLabel(): ?string
    {
        return self::REASONS[$this->reason_code] ?? null;
    }

    /** "CO-003" */
    public function label(): string
    {
        return 'CO-'.str_pad((string) $this->number, 3, '0', STR_PAD_LEFT);
    }

    /** Whether its lines and details may still be changed. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED], true);
    }

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return HasMany<ChangeOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ChangeOrderLine::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<ChangeOrderAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(ChangeOrderAttachment::class)->latest('id');
    }

    /** @return HasMany<ChangeOrderEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ChangeOrderEvent::class)->orderByDesc('id');
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
