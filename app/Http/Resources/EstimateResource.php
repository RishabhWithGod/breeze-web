<?php

namespace App\Http\Resources;

use App\Models\Estimate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Estimate */
class EstimateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'client' => $this->client,
            'project' => $this->project,
            // ISO strings throughout — the client formats with date-fns.
            'date' => $this->issued_on->toISOString(),
            'amount' => (float) $this->amount,
            'status' => $this->status,
        ];
    }
}
