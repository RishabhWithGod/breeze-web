<?php

namespace App\Events;

use App\Models\AiResult;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A reviewer generated the final JSON — the takeoff is signed off. */
class ReviewFinalised
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly AiResult $result) {}
}
