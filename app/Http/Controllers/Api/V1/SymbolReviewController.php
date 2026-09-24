<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\AiResult;
use App\Models\ApprovalHistory;
use App\Models\Project;
use App\Models\SymbolReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Every reviewer action on a detection, from the mobile AI Review / drawing
 * overlay screens — the exact same mutations as web's own
 * `SymbolReviewController`, scoped to the takeoff's own project rather than
 * Inertia's `AiResult` route binding. Each one writes the decision to the
 * `symbol_reviews` row and an audit line to `approval_histories`, same as
 * web; the AI response is never modified.
 */
class SymbolReviewController extends Controller
{
    use ApiResponses;

    public function approve(Request $request, Project $project, SymbolReview $review): JsonResponse
    {
        $result = $this->authorise($request, $project, $review);

        $review->update([
            'status' => SymbolReview::STATUS_APPROVED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $result->recordHistory(
            'approved',
            "Approved {$review->name} ({$review->external_id})",
            $review,
            to: SymbolReview::STATUS_APPROVED,
        );

        return $this->ok(['id' => $review->id, 'status' => $review->status], "{$review->name} approved.");
    }

    public function reject(Request $request, Project $project, SymbolReview $review): JsonResponse
    {
        $result = $this->authorise($request, $project, $review);

        $reason = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ])['reason'] ?? null;

        $review->update([
            'status' => SymbolReview::STATUS_REJECTED,
            'notes' => $reason ?? $review->notes,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $result->recordHistory(
            'rejected',
            "Rejected {$review->name} ({$review->external_id})".($reason ? ": {$reason}" : ''),
            $review,
            to: SymbolReview::STATUS_REJECTED,
        );

        return $this->ok(['id' => $review->id, 'status' => $review->status], "{$review->name} rejected.");
    }

    /** Returns a decided detection to the pending pile. */
    public function reset(Request $request, Project $project, SymbolReview $review): JsonResponse
    {
        $result = $this->authorise($request, $project, $review);

        $from = $review->status;
        $review->update([
            'status' => SymbolReview::STATUS_PENDING,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        $result->recordHistory('reset', "Cleared the decision on {$review->name}", $review, from: $from, to: SymbolReview::STATUS_PENDING);

        return $this->ok(['id' => $review->id, 'status' => $review->status], "{$review->name} is pending review again.");
    }

    /** Reviewed quantity. Accepts an absolute value or a step, so a stepper and a number field can share one endpoint. */
    public function count(Request $request, Project $project, SymbolReview $review): JsonResponse
    {
        $result = $this->authorise($request, $project, $review);

        $validated = $request->validate([
            'count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'step' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
        ]);

        $next = $validated['count'] ?? $review->final_count + (int) ($validated['step'] ?? 0);
        $next = max(0, min(100000, (int) $next));
        $from = $review->final_count;

        if ($next === $from) {
            return $this->ok(['id' => $review->id, 'finalCount' => $review->final_count]);
        }

        $review->update([
            'final_count' => $next,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $result->recordHistory(
            'count_changed',
            "Changed the count for {$review->name} from {$from} to {$next}",
            $review,
            from: (string) $from,
            to: (string) $next,
            meta: ['ai_count' => $review->ai_count],
        );

        return $this->ok(['id' => $review->id, 'finalCount' => $next], "{$review->name} count set to {$next}.");
    }

    /** Renames a detection; the final JSON uses the new name. */
    public function rename(Request $request, Project $project, SymbolReview $review): JsonResponse
    {
        $result = $this->authorise($request, $project, $review);

        $name = trim($request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ])['name']);

        $from = $review->name;

        if ($name === $from) {
            return $this->ok(['id' => $review->id, 'name' => $review->name]);
        }

        $review->update([
            'name' => $name,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $result->recordHistory('renamed', "Renamed {$from} to {$name}", $review, from: $from, to: $name, meta: ['ai_name' => $review->ai_name]);

        return $this->ok(['id' => $review->id, 'name' => $name], "Renamed to \"{$name}\".");
    }

    public function note(Request $request, Project $project, SymbolReview $review): JsonResponse
    {
        $result = $this->authorise($request, $project, $review);

        $note = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ])['notes'] ?? null;

        $review->update(['notes' => $note]);

        $result->recordHistory(
            'note_added',
            blank($note) ? "Cleared the note on {$review->name}" : "Noted on {$review->name}: {$note}",
            $review,
            to: $note,
        );

        return $this->ok(['id' => $review->id, 'notes' => $note], 'Note saved.');
    }

    /**
     * Merges detections into a single symbol. The target keeps the combined
     * count; the sources are marked as merged so they stay auditable but
     * drop out of the final JSON.
     */
    public function merge(Request $request, Project $project): JsonResponse
    {
        $result = $this->authoriseResult($request, $project);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:2'],
            'ids.*' => ['integer', Rule::exists('symbol_reviews', 'id')->where('ai_result_id', $result->id)],
            'target_id' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'min:2', 'max:120'],
        ]);

        $reviews = $result->reviews()->whereIn('id', $validated['ids'])->get();
        $target = $reviews->firstWhere('id', $validated['target_id'] ?? null) ?? $reviews->first();
        $sources = $reviews->reject(fn (SymbolReview $review) => $review->id === $target->id);
        $name = trim($validated['name'] ?? $target->name);

        DB::transaction(function () use ($target, $sources, $name, $request, $result) {
            $target->update([
                'name' => $name,
                'final_count' => $target->final_count + (int) $sources->sum('final_count'),
                'status' => SymbolReview::STATUS_APPROVED,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            foreach ($sources as $source) {
                $source->update([
                    'merged_into_id' => $target->id,
                    'name' => $name,
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                ]);

                $result->recordHistory(
                    'merged',
                    "Merged {$source->external_id} into {$target->external_id} as {$name}",
                    $source,
                    from: $source->ai_name,
                    to: $name,
                    meta: ['target_id' => $target->id],
                );
            }
        });

        $count = $sources->count();

        return $this->ok(
            ['targetId' => $target->id, 'finalCount' => $target->fresh()->final_count],
            "{$count} detections merged into \"{$name}\"."
        );
    }

    /**
     * Splits a detection's count across a new sibling row. Used when the
     * model bundled two device types into one crop: the remainder stays on
     * the original, the split-off quantity becomes its own reviewable
     * symbol carrying the same geometry.
     */
    public function split(Request $request, Project $project, SymbolReview $review): JsonResponse
    {
        $result = $this->authorise($request, $project, $review);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'count' => ['required', 'integer', 'min:1', "max:{$review->final_count}"],
        ]);

        if ($review->final_count <= 1) {
            return $this->fail('A detection counted as one cannot be split — rename it instead.', 422);
        }

        $child = DB::transaction(function () use ($review, $validated, $result, $request) {
            $review->update([
                'final_count' => $review->final_count - $validated['count'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            return $result->reviews()->create([
                'project_id' => $result->project_id,
                'external_id' => $review->external_id.'-s'.($review->id % 1000),
                'ai_name' => $review->ai_name,
                'name' => trim($validated['name']),
                'page' => $review->page,
                'confidence' => $review->confidence,
                'bbox' => $review->bbox,
                'source_template' => $review->source_template,
                'source_vector' => $review->source_vector,
                'source_vision' => $review->source_vision,
                'source_ocr' => $review->source_ocr,
                'pipeline' => $review->pipeline,
                'legend' => $review->legend,
                'is_known' => $review->is_known,
                'ai_count' => 0,
                'final_count' => $validated['count'],
                'status' => SymbolReview::STATUS_APPROVED,
                'split_from_id' => $review->id,
                'crop_path' => $review->crop_path,
                'crop_url' => $review->crop_url,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'position' => $review->position,
            ]);
        });

        $result->recordHistory(
            'split',
            "Split {$validated['count']} of {$review->external_id} into \"{$child->name}\"",
            $review,
            from: (string) ($review->final_count + $validated['count']),
            to: (string) $review->final_count,
            meta: ['child_id' => $child->id, 'child_name' => $child->name],
        );

        return $this->ok(['childId' => $child->id, 'childName' => $child->name], "Split {$validated['count']} off as \"{$child->name}\".");
    }

    /**
     * Toggles one physical occurrence on the drawing overlay between
     * approved and rejected, stepping the card's own `final_count` by
     * exactly one so the drawing and the card grid always agree.
     */
    public function occurrence(Request $request, Project $project, SymbolReview $review, string $key): JsonResponse
    {
        $result = $this->authorise($request, $project, $review);

        $outcome = $this->flipOccurrenceStatus($review, $key, $request->user()->id);
        abort_if($outcome === null, 404);

        $this->recordOccurrenceFlipHistory($result, $review, $key, $outcome);

        return $this->ok(['id' => $review->id, 'finalCount' => $outcome['toCount'], 'status' => $outcome['toStatus']]);
    }

    /**
     * @return array{wasApproved: bool, fromCount: int, toCount: int, fromStatus: string, toStatus: string}|null Null when the key doesn't exist.
     */
    private function flipOccurrenceStatus(SymbolReview $review, string $key, int $userId): ?array
    {
        $occurrences = $review->occurrences ?? [];
        $index = collect($occurrences)->search(fn (array $item) => ($item['key'] ?? null) === $key);

        if ($index === false) {
            return null;
        }

        $occurrence = $occurrences[$index];
        $wasApproved = ($occurrence['status'] ?? SymbolReview::STATUS_APPROVED) === SymbolReview::STATUS_APPROVED;
        $occurrence['status'] = $wasApproved ? SymbolReview::STATUS_REJECTED : SymbolReview::STATUS_APPROVED;
        $occurrences[$index] = $occurrence;

        $fromCount = $review->final_count;
        $toCount = max(0, min(100000, $fromCount + ($wasApproved ? -1 : 1)));
        $approvedRemaining = collect($occurrences)->where('status', SymbolReview::STATUS_APPROVED)->count();
        $fromStatus = $review->status;
        $toStatus = $approvedRemaining === 0 ? SymbolReview::STATUS_REJECTED : SymbolReview::STATUS_APPROVED;

        $review->update([
            'occurrences' => $occurrences,
            'final_count' => $toCount,
            'status' => $toStatus,
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
        ]);

        return compact('wasApproved', 'fromCount', 'toCount', 'fromStatus', 'toStatus');
    }

    /** @param  array{wasApproved: bool, fromCount: int, toCount: int, fromStatus: string, toStatus: string}  $outcome */
    private function recordOccurrenceFlipHistory(AiResult $result, SymbolReview $review, string $key, array $outcome): void
    {
        $result->recordHistory(
            $outcome['wasApproved'] ? 'occurrence_rejected' : 'occurrence_approved',
            ($outcome['wasApproved'] ? "Rejected one occurrence of {$review->name}" : "Approved one occurrence of {$review->name}")
            ." — count {$outcome['fromCount']} to {$outcome['toCount']}"
            .($outcome['fromStatus'] !== $outcome['toStatus'] ? ", status {$outcome['fromStatus']} to {$outcome['toStatus']}" : ''),
            $review,
            from: (string) $outcome['fromCount'],
            to: (string) $outcome['toCount'],
            meta: ['key' => $key, 'from_status' => $outcome['fromStatus'], 'to_status' => $outcome['toStatus']],
        );
    }

    /**
     * Drags an occurrence to a new spot on the drawing. The AI's own
     * position is preserved in `original_bbox` the first time this happens.
     */
    public function moveOccurrence(Request $request, Project $project, SymbolReview $review, string $key): JsonResponse
    {
        $result = $this->authorise($request, $project, $review);

        $occurrences = $review->occurrences ?? [];
        $index = collect($occurrences)->search(fn (array $item) => ($item['key'] ?? null) === $key);
        abort_if($index === false, 404);

        $occurrence = $occurrences[$index];
        $pageSize = $result->pageDimensions()[(int) ($occurrence['page'] ?? 0)] ?? null;
        abort_if($pageSize === null, 422, 'This page has no confirmed dimensions to place a symbol against.');

        $validated = $request->validate([
            'bbox' => ['required', 'array', 'size:4'],
            'bbox.*' => ['required', 'numeric', 'min:0'],
        ]);

        $from = $occurrence['bbox'];
        $to = $this->clampBbox(array_values($validated['bbox']), $pageSize);

        if (! isset($occurrence['original_bbox'])) {
            $occurrence['original_bbox'] = $from;
        }

        $occurrence['bbox'] = $to;
        $occurrences[$index] = $occurrence;

        $review->update([
            'occurrences' => $occurrences,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $result->recordHistory('occurrence_moved', "Moved one occurrence of {$review->name}", $review, meta: ['key' => $key, 'from_bbox' => $from, 'to_bbox' => $to]);

        return $this->ok(['id' => $review->id, 'key' => $key, 'bbox' => $to]);
    }

    /**
     * Copies one occurrence, offset slightly so it's immediately visible and
     * draggable to its real spot.
     */
    public function duplicateOccurrence(Request $request, Project $project, SymbolReview $review, string $key): JsonResponse
    {
        $result = $this->authorise($request, $project, $review);

        $occurrences = $review->occurrences ?? [];
        $index = collect($occurrences)->search(fn (array $item) => ($item['key'] ?? null) === $key);
        abort_if($index === false, 404);

        $source = $occurrences[$index];
        $pageSize = $result->pageDimensions()[(int) ($source['page'] ?? 0)] ?? null;

        [$x, $y, $w, $h] = $source['bbox'];
        $offset = [$x + 20, $y + 20, $w, $h];

        $duplicate = [
            'key' => 'dup_'.Str::random(16),
            'bbox' => $pageSize !== null ? $this->clampBbox($offset, $pageSize) : $offset,
            'page' => $source['page'],
            'confidence' => 1.0,
            'status' => SymbolReview::STATUS_APPROVED,
            'origin' => SymbolReview::OCCURRENCE_ORIGIN_DUPLICATE,
            'duplicatedFrom' => $key,
        ];

        $occurrences[] = $duplicate;
        $from = $review->final_count;

        $review->update([
            'occurrences' => $occurrences,
            'final_count' => $from + 1,
            'status' => SymbolReview::STATUS_APPROVED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $result->recordHistory(
            'occurrence_duplicated',
            "Duplicated one occurrence of {$review->name}",
            $review,
            from: (string) $from,
            to: (string) ($from + 1),
            meta: ['source_key' => $key, 'new_key' => $duplicate['key']],
        );

        return $this->ok(['id' => $review->id, 'finalCount' => $from + 1, 'newKey' => $duplicate['key']], "{$review->name} duplicated.");
    }

    /**
     * Removes an occurrence the reviewer placed themselves — a manual
     * addition or a duplicate. An AI-origin detection is never deleted this
     * way; reject it instead.
     */
    public function deleteOccurrence(Request $request, Project $project, SymbolReview $review, string $key): JsonResponse
    {
        $result = $this->authorise($request, $project, $review);

        $occurrences = $review->occurrences ?? [];
        $index = collect($occurrences)->search(fn (array $item) => ($item['key'] ?? null) === $key);
        abort_if($index === false, 404);

        $occurrence = $occurrences[$index];
        $origin = $occurrence['origin'] ?? SymbolReview::OCCURRENCE_ORIGIN_AI;

        abort_unless(
            in_array($origin, [SymbolReview::OCCURRENCE_ORIGIN_MANUAL, SymbolReview::OCCURRENCE_ORIGIN_DUPLICATE], true),
            403,
            'Only a manually added or duplicated symbol can be deleted — reject an AI detection instead.',
        );

        $name = $review->name;
        $wasApproved = ($occurrence['status'] ?? SymbolReview::STATUS_APPROVED) === SymbolReview::STATUS_APPROVED;

        unset($occurrences[$index]);
        $occurrences = array_values($occurrences);

        if ($occurrences === [] && $review->origin === SymbolReview::ORIGIN_MANUAL) {
            $review->delete();

            $result->recordHistory(
                'occurrence_deleted',
                "Deleted the last occurrence of {$name}, removing the symbol",
                meta: ['key' => $key, 'occurrence' => $occurrence, 'review_deleted' => true],
            );

            return $this->ok(['deleted' => true], "{$name} removed.");
        }

        $next = $wasApproved ? max(0, $review->final_count - 1) : $review->final_count;
        $approvedRemaining = collect($occurrences)->where('status', SymbolReview::STATUS_APPROVED)->count();

        $review->update([
            'occurrences' => $occurrences,
            'final_count' => $next,
            'status' => $approvedRemaining === 0 ? SymbolReview::STATUS_REJECTED : SymbolReview::STATUS_APPROVED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $result->recordHistory('occurrence_deleted', "Deleted one occurrence of {$name}", $review, meta: ['key' => $key, 'occurrence' => $occurrence]);

        return $this->ok(['id' => $review->id, 'finalCount' => $next], "Occurrence of {$name} deleted.");
    }

    /**
     * Places a symbol the AI missed directly on the drawing. Adds an
     * occurrence to an existing card by name (case-insensitive), or creates
     * a brand-new one — either way the count goes up by exactly one.
     */
    public function manualAdd(Request $request, Project $project): JsonResponse
    {
        $result = $this->authoriseResult($request, $project);

        $validated = $request->validate([
            'page' => ['required', 'integer', 'min:1', 'max:'.max(1, (int) $result->page_count)],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'bbox' => ['required', 'array', 'size:4'],
            'bbox.*' => ['required', 'numeric', 'min:0'],
        ]);

        $name = trim($validated['name']);
        $occurrence = [
            'key' => 'man_'.Str::random(16),
            'bbox' => array_values($validated['bbox']),
            'page' => $validated['page'],
            'confidence' => 1.0,
            'status' => SymbolReview::STATUS_APPROVED,
            'origin' => SymbolReview::OCCURRENCE_ORIGIN_MANUAL,
        ];

        $existing = $result->reviews()
            ->whereNull('merged_into_id')
            ->whereRaw('lower(name) = ?', [Str::lower($name)])
            ->first();

        $review = DB::transaction(function () use ($existing, $occurrence, $result, $request, $name) {
            if ($existing !== null) {
                $existing->update([
                    'occurrences' => [...($existing->occurrences ?? []), $occurrence],
                    'final_count' => $existing->final_count + 1,
                    'status' => SymbolReview::STATUS_APPROVED,
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                ]);

                return $existing;
            }

            return $result->reviews()->create([
                'project_id' => $result->project_id,
                'external_id' => Str::slug($name).'-manual-'.Str::random(6),
                'origin' => SymbolReview::ORIGIN_MANUAL,
                'ai_category' => SymbolReview::CATEGORY_KNOWN,
                'ai_name' => $name,
                'name' => $name,
                'page' => $occurrence['page'],
                'confidence' => 1.0,
                'is_known' => true,
                'occurrences' => [$occurrence],
                'ai_count' => 0,
                'final_count' => 1,
                'status' => SymbolReview::STATUS_APPROVED,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'position' => (int) $result->reviews()->max('position') + 1,
            ]);
        });

        $result->recordHistory('manual_added', "Manually added {$name} on page {$occurrence['page']}", $review, to: $name, meta: ['key' => $occurrence['key']]);

        return $this->created(['id' => $review->id, 'name' => $name, 'finalCount' => $review->final_count], "{$name} added.");
    }

    /** Approve, reject or clear a whole selection. */
    public function bulk(Request $request, Project $project): JsonResponse
    {
        $result = $this->authoriseResult($request, $project);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', Rule::exists('symbol_reviews', 'id')->where('ai_result_id', $result->id)],
            'action' => ['required', Rule::in(['approve', 'reject', 'reset'])],
        ]);

        $status = match ($validated['action']) {
            'approve' => SymbolReview::STATUS_APPROVED,
            'reject' => SymbolReview::STATUS_REJECTED,
            'reset' => SymbolReview::STATUS_PENDING,
        };

        $count = $result->reviews()->whereIn('id', $validated['ids'])->update([
            'status' => $status,
            'reviewed_by' => $status === SymbolReview::STATUS_PENDING ? null : $request->user()->id,
            'reviewed_at' => $status === SymbolReview::STATUS_PENDING ? null : now(),
            'updated_at' => now(),
        ]);

        $result->recordHistory('bulk_'.$validated['action'], "{$count} detections set to {$status}", to: $status, meta: ['ids' => $validated['ids']]);

        return $this->ok(['count' => $count, 'status' => $status], "{$count} detections {$status}.");
    }

    /** Approves every still-pending detection on this takeoff in one call — "Approve All Pending". */
    public function approveRemaining(Request $request, Project $project): JsonResponse
    {
        $result = $this->authoriseResult($request, $project);

        $count = $result->reviews()
            ->where('status', SymbolReview::STATUS_PENDING)
            ->update([
                'status' => SymbolReview::STATUS_APPROVED,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

        $result->recordHistory('bulk_approve', "{$count} pending detections approved");

        return $this->ok(['approvedCount' => $count]);
    }

    /**
     * Reverses the single most recent reversible change on this takeoff.
     * Calling it repeatedly walks backward through the audit trail one step
     * at a time — mirrors web's `undoLast()` exactly, including which
     * actions are reversible.
     */
    public function undoLast(Request $request, Project $project): JsonResponse
    {
        $result = $this->authoriseResult($request, $project);

        $reversible = [
            'approved', 'rejected', 'reset',
            'occurrence_approved', 'occurrence_rejected', 'occurrence_moved',
            'occurrence_duplicated', 'occurrence_deleted', 'manual_added',
            'count_changed', 'renamed',
        ];

        $alreadyUndone = $result->history()
            ->where('action', 'undone')
            ->get()
            ->pluck('meta.undid_history_id')
            ->filter()
            ->all();

        $entry = $result->history()
            ->whereIn('action', $reversible)
            ->whereNotIn('id', $alreadyUndone)
            ->latest('id')
            ->first();

        if ($entry === null) {
            return $this->fail('Nothing to undo.', 422);
        }

        $review = $entry->symbol_review_id ? SymbolReview::find($entry->symbol_review_id) : null;
        $userId = $request->user()->id;

        $undone = match ($entry->action) {
            'approved', 'rejected' => $this->undoToPending($result, $review, $entry),
            'reset' => $this->undoReset($result, $review, $entry, $userId),
            'occurrence_approved', 'occurrence_rejected' => $this->undoOccurrenceToggle($result, $review, $entry, $userId),
            'occurrence_moved' => $this->undoOccurrenceMove($result, $review, $entry, $userId),
            'occurrence_duplicated' => $this->undoOccurrenceDuplicate($result, $review, $entry, $userId),
            'occurrence_deleted' => $this->undoOccurrenceDelete($result, $review, $entry, $userId),
            'manual_added' => $this->undoManualAdd($result, $review, $entry, $userId),
            'count_changed' => $this->undoCountChange($result, $review, $entry, $userId),
            'renamed' => $this->undoRename($result, $review, $entry, $userId),
            default => false,
        };

        if (! $undone) {
            return $this->fail('That change could no longer be undone.', 422);
        }

        return $this->ok(null, 'Last change undone.');
    }

    private function undoToPending(AiResult $result, ?SymbolReview $review, ApprovalHistory $entry): bool
    {
        if ($review === null) {
            return false;
        }

        $from = $review->status;
        $review->update(['status' => SymbolReview::STATUS_PENDING, 'reviewed_by' => null, 'reviewed_at' => null]);
        $result->recordHistory('undone', "Undid the decision on {$review->name}", $review, from: $from, to: SymbolReview::STATUS_PENDING, meta: ['undid_history_id' => $entry->id]);

        return true;
    }

    private function undoReset(AiResult $result, ?SymbolReview $review, ApprovalHistory $entry, int $userId): bool
    {
        if ($review === null || $entry->from_value === null) {
            return false;
        }

        $review->update(['status' => $entry->from_value, 'reviewed_by' => $userId, 'reviewed_at' => now()]);
        $result->recordHistory('undone', "Restored {$review->name} to {$entry->from_value}", $review, to: $entry->from_value, meta: ['undid_history_id' => $entry->id]);

        return true;
    }

    private function undoOccurrenceToggle(AiResult $result, ?SymbolReview $review, ApprovalHistory $entry, int $userId): bool
    {
        $key = $entry->meta['key'] ?? null;

        if ($review === null || $key === null) {
            return false;
        }

        $outcome = $this->flipOccurrenceStatus($review, $key, $userId);

        if ($outcome === null) {
            return false;
        }

        $result->recordHistory('undone', "Undid the last change to one occurrence of {$review->name}", $review, meta: ['key' => $key, 'undid_history_id' => $entry->id]);

        return true;
    }

    private function undoOccurrenceMove(AiResult $result, ?SymbolReview $review, ApprovalHistory $entry, int $userId): bool
    {
        $key = $entry->meta['key'] ?? null;
        $fromBbox = $entry->meta['from_bbox'] ?? null;

        if ($review === null || $key === null || $fromBbox === null) {
            return false;
        }

        $occurrences = $review->occurrences ?? [];
        $index = collect($occurrences)->search(fn (array $item) => ($item['key'] ?? null) === $key);

        if ($index === false) {
            return false;
        }

        $occurrences[$index]['bbox'] = $fromBbox;
        $review->update(['occurrences' => $occurrences, 'reviewed_by' => $userId, 'reviewed_at' => now()]);
        $result->recordHistory('undone', "Moved one occurrence of {$review->name} back", $review, meta: ['key' => $key, 'undid_history_id' => $entry->id]);

        return true;
    }

    private function undoOccurrenceDuplicate(AiResult $result, ?SymbolReview $review, ApprovalHistory $entry, int $userId): bool
    {
        $key = $entry->meta['new_key'] ?? null;

        if ($review === null || $key === null) {
            return false;
        }

        $occurrences = collect($review->occurrences ?? [])->reject(fn (array $item) => ($item['key'] ?? null) === $key)->values()->all();

        if (count($occurrences) === count($review->occurrences ?? [])) {
            return false;
        }

        $from = $review->final_count;
        $review->update([
            'occurrences' => $occurrences,
            'final_count' => max(0, $from - 1),
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
        ]);
        $result->recordHistory('undone', "Removed the duplicated occurrence of {$review->name}", $review, from: (string) $from, to: (string) max(0, $from - 1), meta: ['undid_history_id' => $entry->id]);

        return true;
    }

    private function undoOccurrenceDelete(AiResult $result, ?SymbolReview $review, ApprovalHistory $entry, int $userId): bool
    {
        $occurrence = $entry->meta['occurrence'] ?? null;

        if ($review === null || $occurrence === null) {
            return false;
        }

        $occurrences = [...($review->occurrences ?? []), $occurrence];
        $wasApproved = ($occurrence['status'] ?? SymbolReview::STATUS_APPROVED) === SymbolReview::STATUS_APPROVED;
        $from = $review->final_count;
        $next = $wasApproved ? $from + 1 : $from;

        $review->update([
            'occurrences' => $occurrences,
            'final_count' => $next,
            'status' => SymbolReview::STATUS_APPROVED,
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
        ]);
        $result->recordHistory('undone', "Restored a deleted occurrence of {$review->name}", $review, from: (string) $from, to: (string) $next, meta: ['undid_history_id' => $entry->id]);

        return true;
    }

    private function undoManualAdd(AiResult $result, ?SymbolReview $review, ApprovalHistory $entry, int $userId): bool
    {
        $key = $entry->meta['key'] ?? null;

        if ($review === null || $key === null) {
            return false;
        }

        $occurrences = collect($review->occurrences ?? [])->reject(fn (array $item) => ($item['key'] ?? null) === $key)->values()->all();
        $name = $review->name;

        if ($occurrences === [] && $review->origin === SymbolReview::ORIGIN_MANUAL) {
            $review->delete();
            $result->recordHistory('undone', "Removed manually added {$name}", meta: ['key' => $key, 'undid_history_id' => $entry->id]);

            return true;
        }

        $from = $review->final_count;
        $review->update(['occurrences' => $occurrences, 'final_count' => max(0, $from - 1), 'reviewed_by' => $userId, 'reviewed_at' => now()]);
        $result->recordHistory('undone', "Removed a manually added occurrence of {$name}", $review, from: (string) $from, to: (string) max(0, $from - 1), meta: ['undid_history_id' => $entry->id]);

        return true;
    }

    private function undoCountChange(AiResult $result, ?SymbolReview $review, ApprovalHistory $entry, int $userId): bool
    {
        if ($review === null || $entry->from_value === null) {
            return false;
        }

        $to = (int) $entry->from_value;
        $review->update(['final_count' => $to, 'reviewed_by' => $userId, 'reviewed_at' => now()]);
        $result->recordHistory('undone', "Restored the count for {$review->name} to {$to}", $review, to: (string) $to, meta: ['undid_history_id' => $entry->id]);

        return true;
    }

    private function undoRename(AiResult $result, ?SymbolReview $review, ApprovalHistory $entry, int $userId): bool
    {
        if ($review === null || $entry->from_value === null) {
            return false;
        }

        $to = $entry->from_value;
        $review->update(['name' => $to, 'reviewed_by' => $userId, 'reviewed_at' => now()]);
        $result->recordHistory('undone', "Renamed {$review->name} back to {$to}", $review, to: $to, meta: ['undid_history_id' => $entry->id]);

        return true;
    }

    /** Keeps a bbox `[x, y, w, h]` fully inside a page's real pixel bounds. */
    private function clampBbox(array $bbox, array $pageSize): array
    {
        [$x, $y, $w, $h] = array_pad(array_values($bbox), 4, 0);
        $w = max(1.0, min((float) $w, $pageSize['width']));
        $h = max(1.0, min((float) $h, $pageSize['height']));
        $x = max(0.0, min((float) $x, $pageSize['width'] - $w));
        $y = max(0.0, min((float) $y, $pageSize['height'] - $h));

        return [round($x, 2), round($y, 2), round($w, 2), round($h, 2)];
    }

    /** The review must belong to this takeoff's own project, and the project to the signed-in user, and review must still be open. */
    private function authorise(Request $request, Project $project, SymbolReview $review): AiResult
    {
        abort_unless($project->user_id === $request->user()->id, 403);
        $result = $review->aiResult;
        abort_unless($result !== null && $result->project_id === $project->id, 404);
        abort_if($result->isFinalised(), 403, 'This takeoff has already been finalised.');
        $result->touchReviewStarted();

        return $result;
    }

    /** Same gate as {@see authorise()}, for an action that names no single review (merge, manual add, bulk, undo). */
    private function authoriseResult(Request $request, Project $project): AiResult
    {
        abort_unless($project->user_id === $request->user()->id, 403);
        $result = $project->latestAiResult;
        abort_unless($result !== null, 404);
        abort_if($result->isFinalised(), 403, 'This takeoff has already been finalised.');
        $result->touchReviewStarted();

        return $result;
    }
}
