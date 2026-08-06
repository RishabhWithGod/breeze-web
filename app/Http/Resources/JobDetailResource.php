<?php

namespace App\Http\Resources;

use App\Models\Job;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything the job detail screen renders: the record itself plus its team,
 * estimates, notes, attachments, activity timeline and status history.
 *
 * @mixin Job
 */
class JobDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'client' => $this->client,
            'location' => $this->location,
            'description' => $this->description,
            'jobType' => $this->job_type,
            'status' => $this->status,
            'foreman' => $this->foreman ? [
                'id' => $this->foreman->id,
                'name' => $this->foreman->name,
                'initials' => $this->foreman->initials,
            ] : null,
            'startDate' => $this->start_date?->toISOString(),
            'endDate' => $this->end_date?->toISOString(),
            'budget' => $this->budget === null ? null : (float) $this->budget,
            'isArchived' => $this->isArchived(),
            'archivedAt' => $this->archived_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
            'options' => [
                'createEstimate' => $this->create_estimate,
                'assignTeam' => $this->assign_team,
                'notifyClient' => $this->notify_client,
            ],

            'team' => $this->teamMembers->map(fn ($member) => [
                'id' => $member->id,
                'name' => $member->name,
                'initials' => $member->initials,
                'role' => $member->pivot->role_on_job ?: $member->role,
            ])->all(),

            'estimates' => $this->estimates->map(fn ($estimate) => [
                'id' => $estimate->id,
                'number' => $estimate->number,
                'project' => $estimate->project,
                'client' => $estimate->client,
                'date' => $estimate->issued_on->toISOString(),
                'amount' => (float) $estimate->amount,
                'status' => $estimate->status,
                'isConverted' => $estimate->isConverted(),
                'convertedProjectId' => $estimate->converted_project_id,
            ])->all(),

            'notes' => $this->notes->map(fn ($note) => [
                'id' => $note->id,
                'body' => $note->body,
                'author' => $note->author?->name ?? 'Unknown',
                'createdAt' => $note->created_at->toISOString(),
            ])->all(),

            'attachments' => $this->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'name' => $attachment->name,
                'size' => $attachment->size,
                'mime' => $attachment->mime,
                'uploadedBy' => $attachment->uploader?->name ?? 'Unknown',
                'createdAt' => $attachment->created_at->toISOString(),
                'downloadUrl' => route('jobs.attachments.download', [
                    'job' => $this->id,
                    'attachment' => $attachment->id,
                ]),
            ])->all(),

            'activities' => $this->activities->map(fn ($activity) => [
                'id' => $activity->id,
                'type' => $activity->type,
                'description' => $activity->description,
                'meta' => $activity->meta,
                'actor' => $activity->actor?->name ?? 'System',
                'createdAt' => $activity->created_at->toISOString(),
            ])->all(),

            'assignments' => JobAssignmentResource::collection($this->assignments)->resolve(),

            /*
             * Present only for jobs created from a reviewed takeoff. The counts and
             * bill of quantities are the ones copied at creation — not a live read
             * of the takeoff — so a later re-review cannot change what the crew is
             * building to. The links, by contrast, always point at the current
             * documents.
             */
            'takeoff' => $this->ai_result_id === null ? null : [
                'aiResultId' => $this->ai_result_id,
                'projectId' => $this->project_id,
                // False while the counts are still the engine's own.
                'reviewed' => (bool) data_get($this->metadata, 'reviewed', true),
                'drawingName' => data_get($this->metadata, 'drawing_name')
                    ?? data_get($this->metadata, 'drawing.name'),
                'projectName' => data_get($this->metadata, 'project_name'),
                'engineVersion' => data_get($this->metadata, 'engine_version'),
                'engineRunId' => data_get($this->metadata, 'engine_run_id'),
                'processingTime' => data_get($this->metadata, 'processing_time'),
                'pipelineStatus' => collect(data_get($this->metadata, 'pipeline_status', []))
                    ->map(fn ($status, $stage) => ['stage' => (string) $stage, 'status' => (string) $status])
                    ->values(),
                'warnings' => data_get($this->metadata, 'warnings', []),
                'approvedItems' => data_get($this->metadata, 'total_symbols')
                    ?? data_get($this->metadata, 'approved_items'),
                'symbolTypes' => data_get($this->metadata, 'symbol_types'),
                'laborHours' => data_get($this->boq, 'totals.labor_hours'),
                'materialCost' => data_get($this->boq, 'totals.material_cost'),
                'symbolCounts' => $this->symbol_counts ?? [],
                'boqLines' => data_get($this->boq, 'lines', []),
                'wireSizes' => data_get($this->metadata, 'wire_sizes', []),
                'engineEstimate' => data_get($this->metadata, 'engine_estimate', []),

                // Every document the job was built from.
                'pdfUrl' => $this->project_id
                    ? route('drawings.file', $this->project_id)
                    : null,
                'pdfDetailsUrl' => $this->project_id
                    ? route('drawings.show', $this->project_id)
                    : null,
                'annotatedPdfUrl' => route('finals.annotated', $this->ai_result_id),
                'originalJsonUrl' => route('reviews.original', $this->ai_result_id),
                'finalJsonUrl' => route('finals.export', [
                    'result' => $this->ai_result_id,
                    'format' => 'json',
                ]),
                'reviewUrl' => route('reviews.show', $this->ai_result_id),
                'finalSymbolsUrl' => route('finals.show', $this->ai_result_id),
            ],

            'statusHistory' => $this->statusChanges->map(fn ($change) => [
                'id' => $change->id,
                'from' => $change->from_status,
                'to' => $change->to_status,
                'actor' => $change->actor?->name ?? 'System',
                'createdAt' => $change->created_at->toISOString(),
            ])->all(),
        ];
    }
}
