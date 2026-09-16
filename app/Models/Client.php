<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Who the work is for.
 *
 * A client has projects — several, over the years — and one address book that
 * all of them draw on. Everything else (drawings, takeoffs, estimates, jobs)
 * hangs off a project rather than off the client, because those are facts about
 * a piece of work rather than about the person paying for it.
 */
class Client extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'name', 'notes', 'labor_rate'];

    protected function casts(): array
    {
        return [
            'labor_rate' => 'decimal:2',
        ];
    }

    /**
     * What an hour of this client's labor is billed at — this client's own
     * override where one has been set, the usual rate where one has not.
     */
    public function effectiveLaborRate(): float
    {
        return $this->labor_rate !== null
            ? (float) $this->labor_rate
            : (float) config('ai.estimating.labor_rate');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class)->latest('id');
    }

    /**
     * Every site this client has work at, in the order they were added.
     *
     * One book: a client with three projects on the same building records that
     * address once, and correcting it corrects it everywhere.
     *
     * @return HasMany<ClientAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(ClientAddress::class)
            ->orderByDesc('is_primary')
            ->orderBy('position')
            ->orderBy('id');
    }

    /** The one a project defaults to, and the one shown in lists. */
    public function primaryAddress(): HasOne
    {
        return $this->hasOne(ClientAddress::class)->ofMany([
            'is_primary' => 'max',
            'id' => 'min',
        ]);
    }
}
