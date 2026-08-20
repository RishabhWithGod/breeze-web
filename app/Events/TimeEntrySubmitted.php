<?php

namespace App\Events;

use App\Models\TimeEntry;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** An employee submitted a time entry for approval. */
class TimeEntrySubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly TimeEntry $entry) {}
}
