<?php

namespace App\Http\Resources;

use App\Models\InvoiceItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InvoiceItem */
class InvoiceItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'sourceCategory' => $this->source_category,
            'quantity' => (float) $this->quantity,
            'unitPrice' => (float) $this->unit_price,
            'total' => (float) $this->total,
        ];
    }
}
