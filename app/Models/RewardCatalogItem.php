<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RewardCatalogItem extends Model
{
    protected $fillable = ['name', 'description', 'points_required', 'icon', 'stock', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<BreezeBucksRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(BreezeBucksRedemption::class);
    }

    public function isInStock(): bool
    {
        return $this->stock === null || $this->stock > 0;
    }

    public function isRedeemable(): bool
    {
        return $this->is_active && $this->isInStock();
    }
}
