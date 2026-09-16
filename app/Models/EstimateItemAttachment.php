<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A photo filed against one material line — the per-material analogue of
 *  {@see JobTaskAttachment}. */
class EstimateItemAttachment extends Model
{
    protected $fillable = [
        'estimate_item_id', 'uploaded_by', 'name', 'path', 'mime_type', 'size_bytes',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(EstimateItem::class, 'estimate_item_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** True for the formats the review screen can show inline. */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }
}
