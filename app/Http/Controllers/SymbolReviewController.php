<?php

namespace App\Http\Controllers;

use App\Models\AiResult;
use App\Models\SymbolReview;
use App\Services\Ai\ReviewFeedback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Every reviewer action on a detection.
 *
 * Each one writes the decision to the `symbol_reviews` row and an audit line to
 * `approval_histories`. The AI response is never modified, and `ai_name` /
 * `ai_count` are kept so the review screen can always show what the model said.
 */
class SymbolReviewController extends Controller
{
    public function __construct(private readonly ReviewFeedback $feedback) {}

    public function approve(AiResult $result, SymbolReview $review): RedirectResponse
    {
        $this->authorise($result, $review);

        $review->update([
            'status' => SymbolReview::STATUS_APPROVED,
            'reviewed_by' => request()->user()->id,
            'reviewed_at' => now(),
        ]);

        $result->recordHistory(
            'approved',
            "Approved {$review->name} ({$review->external_id})",
            $review,
            to: SymbolReview::STATUS_APPROVED,
        );

        // Teach the engine's symbol library. Best-effort: never blocks the review.
        $this->feedback->approved($result, $review);

        return back()->with('success', "{$review->name} approved.");
    }

    public function reject(Request $request, AiResult $result, SymbolReview $review): RedirectResponse
    {
        $this->authorise($result, $review);

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

        $this->feedback->rejected($result, $review);

        return back()->with('warning', "{$review->name} rejected — it will not appear in the final JSON.");
    }

    /** Returns a decided detection to the pending pile. */
    public function reset(AiResult $result, SymbolReview $review): RedirectResponse
    {
        $this->authorise($result, $review);

        $from = $review->status;
        $review->update([
            'status' => SymbolReview::STATUS_PENDING,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        $result->recordHistory(
            'reset',
            "Cleared the decision on {$review->name}",
            $review,
            from: $from,
            to: SymbolReview::STATUS_PENDING,
        );

        return back()->with('success', "{$review->name} is pending review again.");
    }

    /**
     * Reviewed quantity. Accepts an absolute value or a step, so the card's
     * +/- buttons and its number field share one endpoint.
     */
    public function count(Request $request, AiResult $result, SymbolReview $review): RedirectResponse
    {
        $this->authorise($result, $review);

        $validated = $request->validate([
            'count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'step' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
        ]);

        $next = $validated['count'] ?? $review->final_count + (int) ($validated['step'] ?? 0);
        $next = max(0, min(100000, (int) $next));
        $from = $review->final_count;

        if ($next === $from) {
            return back();
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

        return back()->with('success', "{$review->name} count set to {$next}.");
    }

    /** Renames a detection; the final JSON uses the new name. */
    public function rename(Request $request, AiResult $result, SymbolReview $review): RedirectResponse
    {
        $this->authorise($result, $review);

        $name = trim($request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ])['name']);

        $from = $review->name;

        if ($name === $from) {
            return back();
        }

        $review->update([
            'name' => $name,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $result->recordHistory(
            'renamed',
            "Renamed {$from} to {$name}",
            $review,
            from: $from,
            to: $name,
            meta: ['ai_name' => $review->ai_name],
        );

        $this->feedback->renamed($result, $review, $from, $name);

        return back()->with('success', "Renamed to “{$name}”.");
    }

    public function note(Request $request, AiResult $result, SymbolReview $review): RedirectResponse
    {
        $this->authorise($result, $review);

        $note = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ])['notes'] ?? null;

        $review->update(['notes' => $note]);

        $result->recordHistory(
            'note_added',
            blank($note)
                ? "Cleared the note on {$review->name}"
                : "Noted on {$review->name}: {$note}",
            $review,
            to: $note,
        );

        return back()->with('success', 'Note saved.');
    }

    /**
     * Merges detections into a single symbol.
     *
     * The target keeps the combined count; the sources are marked as merged so
     * they stay auditable but drop out of the final JSON.
     */
    public function merge(Request $request, AiResult $result): RedirectResponse
    {
        $this->authorize('review', $result);

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

                $this->feedback->merged($result, $source, $target);
            }
        });

        $count = $sources->count();

        return back()->with(
            'success',
            "{$count} ".str('detection')->plural($count)." merged into “{$name}”."
        );
    }

    /**
     * Splits a detection's count across a new sibling row.
     *
     * Used when the model bundled two device types into one crop: the remainder
     * stays on the original, the split-off quantity becomes its own reviewable
     * symbol carrying the same geometry.
     */
    public function split(Request $request, AiResult $result, SymbolReview $review): RedirectResponse
    {
        $this->authorise($result, $review);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'count' => ['required', 'integer', 'min:1', "max:{$review->final_count}"],
        ]);

        if ($review->final_count <= 1) {
            return back()->with(
                'warning',
                'A detection counted as one cannot be split — rename it instead.'
            );
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
            "Split {$validated['count']} of {$review->external_id} into “{$child->name}”",
            $review,
            from: (string) ($review->final_count + $validated['count']),
            to: (string) $review->final_count,
            meta: ['child_id' => $child->id, 'child_name' => $child->name],
        );

        return back()->with('success', "Split {$validated['count']} off as “{$child->name}”.");
    }

    /** Approve, reject or clear a whole selection. */
    public function bulk(Request $request, AiResult $result): RedirectResponse
    {
        $this->authorize('review', $result);

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

        $result->touchReviewStarted();

        $count = $result->reviews()->whereIn('id', $validated['ids'])->update([
            'status' => $status,
            'reviewed_by' => $status === SymbolReview::STATUS_PENDING ? null : $request->user()->id,
            'reviewed_at' => $status === SymbolReview::STATUS_PENDING ? null : now(),
            'updated_at' => now(),
        ]);

        $result->recordHistory(
            'bulk_'.$validated['action'],
            "{$count} detections set to {$status}",
            to: $status,
            meta: ['ids' => $validated['ids']],
        );

        return back()->with(
            $validated['action'] === 'reject' ? 'warning' : 'success',
            "{$count} ".str('detection')->plural($count)." {$status}."
        );
    }

    /** Approves everything still pending — the usual way to clear a clean run. */
    public function approveRemaining(Request $request, AiResult $result): RedirectResponse
    {
        $this->authorize('review', $result);
        $result->touchReviewStarted();

        $count = $result->reviews()
            ->where('status', SymbolReview::STATUS_PENDING)
            ->update([
                'status' => SymbolReview::STATUS_APPROVED,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

        $result->recordHistory('bulk_approve', "{$count} pending detections approved");

        return back()->with('success', "{$count} pending ".str('detection')->plural($count).' approved.');
    }

    /** Shared guard: the row must belong to this result, and it must be open. */
    private function authorise(AiResult $result, SymbolReview $review): void
    {
        $this->authorize('review', $result);
        abort_unless($review->ai_result_id === $result->id, 404);
        $result->touchReviewStarted();
    }
}
