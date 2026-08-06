<?php

namespace App\Http\Resources;

use App\Models\FinalSymbol;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FinalSymbol */
class FinalSymbolResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'count' => $this->count,
            'confidence' => round($this->confidence, 4),
            'template' => $this->source_template,
            'vector' => $this->source_vector,
            'vision' => $this->source_vision,
            'ocr' => $this->source_ocr,
            'sources' => $this->sourceLabels(),
            'pages' => $this->pages ?? [],
            'wasModified' => $this->was_modified,
            'wasRenamed' => $this->was_renamed,
        ];
    }
}
