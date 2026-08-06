<?php

namespace App\Http\Resources;

use App\Models\DetectedSymbol;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DetectedSymbol */
class DetectedSymbolResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category,
            'count' => $this->count,
            'confidence' => $this->confidence,
            'unit' => $this->unit,
        ];
    }
}
