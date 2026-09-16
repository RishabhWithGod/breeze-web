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
            /*
             * Where the rate came from. Two identical-looking figures on the
             * same page can be a price the company has charged and a constant
             * standing in for one; the estimate has to say which, or a guess
             * goes out as a quote.
             */
            'pricingSource' => $this->pricing_source,
            'pricingConfidence' => $this->pricing_confidence,
            'projectRateItemId' => $this->project_rate_item_id,
            'priceBookItemId' => $this->price_book_item_id,
        ];
    }
}
