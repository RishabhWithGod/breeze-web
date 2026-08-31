<?php

namespace App\Http\Resources;

use App\Models\DrawingSheet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DrawingSheet */
class DrawingSheetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'pageCount' => $this->page_count,
            'scale' => $this->scale,
            'symbolCount' => $this->symbol_count,
        ];
    }
}
