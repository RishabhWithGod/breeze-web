<?php

namespace App\Models;

use App\Services\Ai\LifecycleReader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The AI response for a run, and the reviewed result derived from it.
 *
 * `original_payload` is written once and never touched again. `final_payload`
 * appears only after the reviewer generates the final JSON, and is the sole
 * input to job and estimate creation.
 */
class AiResult extends Model
{
    public const REVIEW_PENDING = 'pending';

    public const REVIEW_IN_PROGRESS = 'in-review';

    public const REVIEW_FINALISED = 'finalised';

    protected $fillable = [
        'ai_job_id',
        'project_id',
        'upload_id',
        /** Copied from the upload — see `Upload::addendum_for_estimate_id`. */
        'addendum_for_estimate_id',
        'run_id',
        'project_name',
        'original_payload',
        'original_path',
        'final_payload',
        'final_path',
        'model_version',
        'page_count',
        'page_sizes',
        'detection_count',
        'overall_confidence',
        'processing_time',
        'pipeline_status',
        'warnings',
        'symbol_counts',
        'ai_estimate',
        'lifecycle_statistics',
        'review_status',
        'finalised_by',
        'received_at',
        'finalised_at',
        'work_job_id',
        'estimate_id',
    ];

    protected function casts(): array
    {
        return [
            'original_payload' => 'array',
            'final_payload' => 'array',
            'page_count' => 'integer',
            'page_sizes' => 'array',
            'detection_count' => 'integer',
            'overall_confidence' => 'float',
            'processing_time' => 'float',
            'pipeline_status' => 'array',
            'warnings' => 'array',
            'symbol_counts' => 'array',
            'ai_estimate' => 'array',
            'lifecycle_statistics' => 'array',
            'received_at' => 'datetime',
            'finalised_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiJob, $this> */
    public function aiJob(): BelongsTo
    {
        return $this->belongsTo(AiJob::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Upload, $this> */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    /** The estimate this run is an addendum for, when it was raised through that flow. */
    public function addendumForEstimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class, 'addendum_for_estimate_id');
    }

    /** @return HasMany<SymbolReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(SymbolReview::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<FinalSymbol, $this> */
    public function finalSymbols(): HasMany
    {
        return $this->hasMany(FinalSymbol::class)->orderBy('position');
    }

    /** @return HasMany<PanelSchedule, $this> */
    public function panelSchedules(): HasMany
    {
        return $this->hasMany(PanelSchedule::class)->orderBy('position');
    }

    /** @return HasMany<EquipmentItem, $this> */
    public function equipment(): HasMany
    {
        return $this->hasMany(EquipmentItem::class)->orderBy('position');
    }

    /** @return HasMany<WireSize, $this> */
    public function wireSizes(): HasMany
    {
        return $this->hasMany(WireSize::class)->orderBy('position');
    }

    /** @return HasMany<Circuit, $this> */
    public function circuits(): HasMany
    {
        return $this->hasMany(Circuit::class)->orderBy('position');
    }

    /** The engine's own priced bill of quantities. */
    public function boqLines(): HasMany
    {
        return $this->hasMany(BoqLine::class)->orderBy('position');
    }

    /** @return HasMany<ApprovalHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(ApprovalHistory::class)->latest('id');
    }

    /** @return BelongsTo<Job, $this> */
    public function workJob(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'work_job_id');
    }

    /** @return BelongsTo<Estimate, $this> */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class, 'estimate_id');
    }

    public function isFinalised(): bool
    {
        return $this->review_status === self::REVIEW_FINALISED;
    }

    /** True once crop images can be fetched for this run. */
    public function hasLifecycle(): bool
    {
        return filled($this->run_id);
    }

    /**
     * Pipeline status as an ordered list, in the engine's own stage order.
     *
     * @return list<array{stage: string, status: string}>
     */
    public function pipelineStages(): array
    {
        $reported = $this->pipeline_status ?? [];
        $order = ['legend', 'template', 'vector', 'vision', 'tables'];

        return collect($order)
            ->filter(fn (string $stage) => array_key_exists($stage, $reported))
            ->map(fn (string $stage) => ['stage' => $stage, 'status' => (string) $reported[$stage]])
            ->concat(
                collect($reported)
                    ->except($order)
                    ->map(fn ($status, $stage) => ['stage' => (string) $stage, 'status' => (string) $status])
                    ->values()
            )
            ->values()
            ->all();
    }

    /** Review counters shown above the symbol grid. */
    public function reviewTally(): array
    {
        $reviews = $this->relationLoaded('reviews') ? $this->reviews : $this->reviews()->get();

        // Mirrors SymbolReview::scopeVisible() — an approved row with nothing
        // to count is excluded from every tab; pending/rejected always count.
        $reviews = $reviews->filter(
            fn (SymbolReview $review) => $review->status !== SymbolReview::STATUS_APPROVED || $review->final_count > 0,
        );

        return [
            'total' => $reviews->count(),
            'pending' => $reviews->where('status', SymbolReview::STATUS_PENDING)->count(),
            'approved' => $reviews->where('status', SymbolReview::STATUS_APPROVED)->count(),
            'rejected' => $reviews->where('status', SymbolReview::STATUS_REJECTED)->count(),
            'modified' => $reviews->filter->isModified()->count(),
            'known' => $reviews->where('is_known', true)->count(),
            'unknown' => $reviews->where('is_known', false)->count(),
            // Quantity actually going into the final JSON right now.
            'approvedCount' => (int) $reviews
                ->where('status', SymbolReview::STATUS_APPROVED)
                ->whereNull('merged_into_id')
                ->sum('final_count'),
        ];
    }

    /** Appends an audit row. */
    public function recordHistory(
        string $action,
        string $description,
        ?SymbolReview $review = null,
        ?string $from = null,
        ?string $to = null,
        array $meta = [],
    ): ApprovalHistory {
        return $this->history()->create([
            'project_id' => $this->project_id,
            'symbol_review_id' => $review?->id,
            'user_id' => Auth::id(),
            'action' => $action,
            'subject' => $review?->name,
            'from_value' => $from,
            'to_value' => $to,
            /*
             * Bounded at the source. Some descriptions carry a database error
             * verbatim, which can run to thousands of characters — SQLite quietly
             * truncated those, MySQL rejects the row and would lose the audit entry
             * altogether. Losing the tail of a message beats losing the record.
             */
            'description' => Str::limit($description, 1000, preserveWords: false),
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    /**
     * The engine's own page dimensions, needed to map a box's pixel
     * coordinates onto the rendered page — as a percentage, so the overlay
     * never has to measure the `<img>` itself.
     *
     * Sourced from `page_sizes` — the engine's own page-info endpoint,
     * fetched and persisted by {@see LifecycleReader::pageSizes()}
     * — the only place real per-page raster dimensions are ever available.
     * The upload response itself never carries them (`original_payload.pages`
     * is just a page count for this engine version). A page this run has no
     * confirmed size for is simply absent from the result: nothing here
     * invents a dimension, so an absent page renders no boxes rather than
     * ones placed against a guessed frame.
     *
     * @return array<int, array{width: float, height: float}>
     */
    public function pageDimensions(): array
    {
        $sizes = $this->page_sizes ?? [];

        return collect(is_array($sizes) ? $sizes : [])
            ->mapWithKeys(fn ($size, $page) => is_array($size)
                ? [(int) $page => [
                    'width' => (float) ($size['width'] ?? 0),
                    'height' => (float) ($size['height'] ?? 0),
                ]]
                : [])
            ->filter(fn (array $size) => $size['width'] > 0 && $size['height'] > 0)
            ->all();
    }

    /** Moves a pending review into "in-review" the first time it is touched. */
    public function touchReviewStarted(): void
    {
        if ($this->review_status === self::REVIEW_PENDING) {
            $this->update(['review_status' => self::REVIEW_IN_PROGRESS]);
            $this->project->update(['review_status' => self::REVIEW_IN_PROGRESS]);
        }
    }
}
