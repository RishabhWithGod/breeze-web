<?php

namespace App\Events;

use App\Models\AiJob;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A run could not be completed by the AI service. */
class TakeoffFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly AiJob $aiJob,
        public readonly string $reason,
    ) {}
}
