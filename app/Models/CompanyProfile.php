<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The company an account works for, described by that account on first sign-in
 * and read wherever the company needs to be named.
 */
class CompanyProfile extends Model
{
    public const LOGO_DISK = 'public';

    protected $fillable = [
        'name',
        'business_address',
        'primary_contact',
        'phone',
        'email',
        'license_number',
        'timezone',
        'logo_path',
        'user_id',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logoUrl(): ?string
    {
        // A path on this site rather than an absolute address, so it loads whichever
        // host or port the app is being opened from.
        return $this->logo_path ? '/storage/'.ltrim($this->logo_path, '/') : null;
    }
}
