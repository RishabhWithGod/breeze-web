<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The rate this system quotes for one item, in one unit.
 *
 * Derived from {@see PriceBookLine} and editable afterwards. `is_pinned` marks
 * a rate somebody has settled by hand: the next import refreshes what it has
 * seen but leaves the figure alone, because an estimator's decision outranks an
 * average of old jobs.
 *
 * `user_id` is null for the universal book and set to its owner otherwise —
 * see {@see PriceBookImport}.
 */
class PriceBookItem extends Model
{
    protected $fillable = [
        'user_id', 'match_key', 'unit', 'description', 'section', 'subsection',
        'unit_material_cost', 'unit_manhours',
        'sample_count', 'min_material_cost', 'max_material_cost',
        'min_manhours', 'max_manhours', 'is_pinned', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return ['is_pinned' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    /** The lines this rate was derived from. */
    public function lines()
    {
        return $this->hasMany(PriceBookLine::class, 'match_key', 'match_key')
            ->where('unit', $this->unit)
            ->where('user_id', $this->user_id);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        return $term === ''
            ? $query
            : $query->where('description', 'like', "%{$term}%");
    }
}
