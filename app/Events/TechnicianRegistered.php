<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A technician just self-registered from the mobile app and is awaiting approval. */
class TechnicianRegistered
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly User $technician) {}
}
