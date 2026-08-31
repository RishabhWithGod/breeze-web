<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Only the hash is ever stored — the plaintext is shown once, at generation time. */
class TwoFactorRecoveryCode extends Model
{
    protected $fillable = ['user_id', 'code_hash', 'used_at'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
