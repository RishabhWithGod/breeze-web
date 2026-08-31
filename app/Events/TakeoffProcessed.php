<?php

namespace App\Events;

use App\Models\AiResult;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** The AI response landed and is ready to be reviewed. */
class TakeoffProcessed
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly AiResult $result) {}
}
