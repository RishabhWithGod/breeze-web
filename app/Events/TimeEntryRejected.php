<?php

namespace App\Events;

use App\Models\TimeEntry;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A manager rejected a time entry. */
class TimeEntryRejected
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly TimeEntry $entry) {}
}
