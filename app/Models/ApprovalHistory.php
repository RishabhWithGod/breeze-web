<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit row. Written by the review, finalisation, job, estimate and
 * assignment paths; never updated.
 */
class ApprovalHistory extends Model
{
    protected $table = 'approval_histories';

    protected $fillable = [
        'ai_result_id',
        'project_id',
        'symbol_review_id',
        'user_id',
        'action',
        'subject',
        'from_value',
        'to_value',
        'description',
        'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<AiResult, $this> */
    public function aiResult(): BelongsTo
    {
        return $this->belongsTo(AiResult::class);
    }

    /** @return BelongsTo<SymbolReview, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(SymbolReview::class, 'symbol_review_id');
    }
}
