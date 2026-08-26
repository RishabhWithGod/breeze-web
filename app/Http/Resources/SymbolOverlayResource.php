<?php

namespace App\Http\Resources;

use App\Models\SymbolReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The drawing overlay's own slice of a symbol review row — deliberately
 * smaller than {@see SymbolReviewResource}, since this is unpaginated and
 * every row on the result must carry its full `occurrences` array.
 *
 * @mixin SymbolReview
 */
class SymbolOverlayResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'page' => $this->page,
            'bbox' => $this->bbox,
            'status' => $this->status,
            'finalCount' => $this->final_count,
            'aiCount' => $this->ai_count,
            'origin' => $this->origin,
            'occurrences' => $this->occurrences,
        ];
    }
}
