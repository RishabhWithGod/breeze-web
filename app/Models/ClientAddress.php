<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One site a client has work at.
 *
 * Coordinates are set only when the address was picked from the lookup, so both
 * are null far more often than not — see MapboxGeocoder.
 */
class ClientAddress extends Model
{
    protected $fillable = [
        'project_id',
        'label',
        'address',
        'latitude',
        'longitude',
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

    /** The client this site belongs to. Clients are projects. */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
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
