<?php

namespace App\Http\Resources;

use App\Models\SymbolReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SymbolReview */
class SymbolReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'externalId' => $this->external_id,
            // `crop_0001`-style id from the engine's lifecycle, when resolved.
            'cropId' => $this->crop_id,
            'origin' => $this->origin,
            'aiCategory' => $this->ai_category,
            'reason' => $this->reason,
            'name' => $this->name,
            'aiName' => $this->ai_name,
            'page' => $this->page,
            'confidence' => round($this->confidence, 4),
            'bbox' => $this->bbox,
            'sources' => [
                'template' => $this->source_template,
                'vector' => $this->source_vector,
                'vision' => $this->source_vision,
                'ocr' => $this->source_ocr,
            ],
            'sourceLabels' => $this->sourceLabels(),
            // Corroborating signals the engine listed: legend, template, vector…
            'evidence' => $this->evidence ?? [],
            'detectionSource' => $this->detection_source,
            'finalDecision' => $this->final_decision,
            'cropCount' => $this->crop_count,
            'pipeline' => $this->pipeline ?? [],
            'stages' => $this->stages ?? [],
            'legend' => $this->legend,
            'isKnown' => $this->is_known,
            'aiCount' => $this->ai_count,
            'finalCount' => $this->final_count,
            'status' => $this->status,
            'notes' => $this->notes,
            'isModified' => $this->isModified(),
            'isRenamed' => $this->isRenamed(),
            'mergedIntoId' => $this->merged_into_id,
            'splitFromId' => $this->split_from_id,
            /*
             * Crops are served through the app: the filed copy when we have one,
             * otherwise fetched from the engine on first request.
             */
            'cropUrl' => ($this->crop_path || $this->image_path)
                ? route('reviews.crop', ['result' => $this->ai_result_id, 'review' => $this->id])
                : $this->crop_url,
            'reviewedBy' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->name),
            'reviewedAt' => $this->reviewed_at?->toISOString(),
        ];
    }
}
