<?php

namespace App\Http\Resources;

use App\Models\EstimateItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EstimateItem */
class EstimateItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'description' => $this->description,
            'unit' => $this->unit,
            'quantity' => (float) $this->quantity,
            'unitCost' => (float) $this->unit_cost,
            'total' => (float) $this->total,
            'source' => $this->source,
        ];
    }
}
