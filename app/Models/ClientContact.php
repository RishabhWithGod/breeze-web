<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person at a client — a name, what they do there, and how to reach
 * them. A client is rarely one person, so this is a list rather than a
 * single email/phone pair on the client itself.
 */
class ClientContact extends Model
{
    protected $fillable = ['client_id', 'name', 'role', 'email', 'phone', 'is_primary', 'position'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** First letters of the first two words — the avatar initials. */
    public function initials(): string
    {
        return str($this->name)
            ->squish()
            ->explode(' ')
            ->take(2)
            ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('') ?: mb_strtoupper(mb_substr($this->name, 0, 2));
    }
}
