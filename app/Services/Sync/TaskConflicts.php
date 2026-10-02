<?php

namespace App\Services\Sync;

use App\Models\JobTask;
use Illuminate\Support\Carbon;

/**
 * Tells whether a task change made in the field, perhaps hours ago with no signal, clashes with an
 * edit the office made to the same task in the meantime.
 *
 * Changes to different things never clash — the field marking a task complete while the office
 * reassigns it just both happen. It clashes only when the field and the office each moved the same
 * field (status, notes) to different values since the field last looked: the phone says what it
 * last saw (`base_*`), and the task as it is now says what the office did.
 */
class TaskConflicts
{
    private const LABELS = ['status' => 'Task Status', 'notes' => 'Notes'];

    /**
     * @param  array<string, mixed>  $incoming  status and/or notes the field wants to set
     * @param  array<string, mixed>  $base  base_status / base_notes the phone last saw (absent: no check)
     * @return list<array<string, mixed>>
     */
    public function detect(JobTask $task, array $incoming, array $base, ?Carbon $fieldAt): array
    {
        $fields = [];

        if (array_key_exists('status', $incoming) && array_key_exists('base_status', $base)) {
            $current = $task->status;
            if ($current !== $base['base_status'] && $current !== $incoming['status']) {
                $fields[] = $this->field('status', $incoming['status'], $current, $fieldAt, $task);
            }
        }

        if (filled($incoming['notes'] ?? null) && array_key_exists('base_notes', $base)) {
            $current = (string) ($task->notes ?? '');
            $was = (string) ($base['base_notes'] ?? '');
            if ($current !== $was && $current !== trim((string) $incoming['notes'])) {
                $fields[] = $this->field('notes', trim((string) $incoming['notes']), $current, $fieldAt, $task);
            }
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function field(string $key, mixed $fieldValue, mixed $officeValue, ?Carbon $fieldAt, JobTask $task): array
    {
        return [
            'key' => $key,
            'label' => self::LABELS[$key],
            'kind' => $key === 'status' ? 'choice' : 'text',
            'options' => $key === 'status' ? JobTask::STATUSES : null,
            'field' => ['value' => $fieldValue, 'at' => ($fieldAt ?? now())->toISOString()],
            'office' => ['value' => $officeValue, 'at' => $task->updated_at?->toISOString()],
        ];
    }

    /** The 409 body the phone's Conflict Review reads. @param list<array<string, mixed>> $fields */
    public function payload(JobTask $task, array $fields, bool $canOverride): array
    {
        return [
            'code' => 'conflict',
            'conflict' => [
                'entity' => 'task',
                'entityId' => $task->id,
                'jobId' => $task->job_id,
                'title' => $task->title,
                'fields' => $fields,
                'canOverride' => $canOverride,
            ],
        ];
    }
}
