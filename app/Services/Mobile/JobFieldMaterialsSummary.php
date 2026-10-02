<?php

namespace App\Services\Mobile;

use App\Models\EstimateItem;
use App\Models\Job;
use App\Models\JobFieldMaterial;

/**
 * Shapes what the crew reported from the mobile "Material and Work Changes"
 * screen for the web job page: planned estimate lines against their actual
 * quantity, and the materials added on site that were never planned.
 *
 * Over plan and any added material need the office's review (they are billable
 * exceptions); under plan is informational only.
 */
class JobFieldMaterialsSummary
{
    /**
     * @return array{planned: list<array<string, mixed>>, added: list<array<string, mixed>>, needsReviewCount: int, addedTotal: float}
     */
    public function for(Job $job): array
    {
        $job->loadMissing('tasks.estimateItems');
        $reports = $job->fieldMaterials()->with('reporter:id,name', 'task:id,title')->orderBy('id')->get();

        $actuals = $reports->whereNotNull('estimate_item_id')->keyBy('estimate_item_id');

        $planned = [];
        foreach ($job->tasks->sortBy([['position', 'asc'], ['id', 'asc']]) as $task) {
            foreach ($task->estimateItems as $item) {
                if ($item->category === EstimateItem::CATEGORY_LABOR) {
                    continue;
                }
                /** @var JobFieldMaterial|null $report */
                $report = $actuals->get($item->id);
                $plannedQty = (float) $item->quantity;
                $actualQty = $report ? (float) $report->actual_quantity : null;

                $planned[] = [
                    'id' => $item->id,
                    'taskId' => $task->id,
                    'taskTitle' => $task->title,
                    'description' => $item->description,
                    'unit' => $item->unit,
                    'plannedQty' => $plannedQty,
                    'actualQty' => $actualQty,
                    'reason' => $report?->reason,
                    'exception' => match (true) {
                        $actualQty === null => null,
                        $actualQty > $plannedQty => 'over',
                        $actualQty < $plannedQty => 'under',
                        default => null,
                    },
                    'reportedBy' => $report?->reporter?->name,
                    'reportedAt' => $report?->updated_at?->toIso8601String(),
                ];
            }
        }

        $added = $reports->whereNull('estimate_item_id')->map(fn (JobFieldMaterial $row) => [
            'id' => $row->id,
            'kind' => $row->kind,
            'description' => $row->description,
            'unit' => $row->unit,
            'qty' => (float) $row->actual_quantity,
            'unitPrice' => (float) $row->unit_price,
            'total' => (float) $row->total,
            'status' => $row->status,
            'reason' => $row->reason,
            'taskTitle' => $row->task?->title,
            'addedBy' => $row->reporter?->name,
            'createdAt' => $row->created_at->toIso8601String(),
        ])->values()->all();

        return [
            'planned' => $planned,
            'added' => $added,
            'needsReviewCount' => count(array_filter($added, fn ($a) => $a['status'] === 'pending'))
                + count(array_filter($planned, fn ($p) => $p['exception'] === 'over')),
            'addedTotal' => round((float) array_sum(array_column($added, 'total')), 2),
        ];
    }
}
