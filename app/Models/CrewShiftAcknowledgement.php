<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A technician's "seen it" on one crew shift. */
class CrewShiftAcknowledgement extends Model
{
    protected $fillable = ['crew_shift_id', 'user_id', 'acknowledged_at'];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }
}
