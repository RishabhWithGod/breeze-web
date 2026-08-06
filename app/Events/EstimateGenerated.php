<?php

namespace App\Events;

use App\Models\Estimate;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** An estimate was generated from a reviewed takeoff. */
class EstimateGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Estimate $estimate) {}
}
