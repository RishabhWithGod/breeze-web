<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One site a client has work at.
 *
 * Coordinates are set only when the address was picked from the lookup, so both
 * are null far more often than not — see GooglePlaces.
 */
class ClientAddress extends Model
{
    /**
     * What kind of building a site is.
     *
     * The same three a job and a project use — a job raised here takes its type
     * from the site, so the two vocabularies have to be one.
     */
    public const TYPES = Job::TYPES;

    protected $fillable = [
        'client_id',
        'label',
        'address',
        'site_type',
        'latitude',
        'longitude',
        'place_id',
        'is_primary',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_primary' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsToMany<Job, $this> */
    public function jobs(): BelongsToMany
    {
        return $this->belongsToMany(Job::class, 'job_addresses');
    }

    /** How the address reads in a list: its label, then the address itself. */
    public function display(): string
    {
        return filled($this->label) ? "{$this->label} — {$this->address}" : $this->address;
    }
}
