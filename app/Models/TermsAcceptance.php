<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One account's signed agreement to one version of the terms. */
class TermsAcceptance extends Model
{
    protected $fillable = ['user_id', 'version', 'signer_name', 'signed_on', 'ip_address', 'accepted_at'];

    protected function casts(): array
    {
        return [
            'signed_on' => 'date',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
