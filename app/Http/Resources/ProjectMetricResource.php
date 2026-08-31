<?php

namespace App\Http\Resources;

use App\Models\ProjectMetric;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProjectMetric */
class ProjectMetricResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'value' => $this->value,
            'delta' => $this->delta,
            'trend' => $this->trend,
            'hint' => $this->hint,
        ];
    }
}
