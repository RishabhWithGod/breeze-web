<?php

namespace App\Services\Takeoff;

use App\Models\AiResult;
use App\Models\Job;
use App\Models\JobAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Signing off a review completes the whole handoff, in one transaction.
 *
 * Finalising used to stop at the reviewed document and leave the job and estimate
 * to a second click, which meant a takeoff could sit "finished" with nothing
 * downstream. Everything a signed-off takeoff implies now happens together:
 *
 *   final_response.json + final symbol table + bill of quantities
 *   → job (from that document only)
 *   → estimate (priced from the engine's bill of quantities)
 *   → the reviewer recorded against the job
 *   → audit trail
 *
 * All of it inside a single `DB::transaction`, so a failure anywhere leaves the
 * takeoff exactly as it was — no half-built job, no orphan estimate. Notifications
 * are dispatched by listeners marked `ShouldHandleEventsAfterCommit`, so nobody is
 * told about work that rolled back.
 *
 * Every step is idempotent: re-finalising a reopened takeoff rebuilds the document
 * and reuses the job and estimate it already has.
 */
class CompleteReview
{
    public function __construct(
        private readonly FinalJsonBuilder $finalJson,
        private readonly JobFactory $jobs,
        private readonly EstimateBuilder $estimates,
    ) {}

    /**
     * @param  array<string, mixed>  $jobAttributes  Overrides for the created job.
     *
     * @throws RuntimeException Nothing approved yet, or nothing to price.
     * @throws Throwable Anything unexpected, after the transaction rolls back.
     */
    public function handle(AiResult $result, User $reviewer, array $jobAttributes = []): Job
    {
        try {
            return DB::transaction(function () use ($result, $reviewer, $jobAttributes): Job {
                // 1. The reviewed document: only approved symbols, at reviewed values.
                $this->finalJson->build($result, $reviewer);

                // 2. The job, built from that document alone.
                $job = $this->jobs->fromFinalJson($result->refresh(), $reviewer, $jobAttributes);

                // 3. The estimate, priced from the engine's bill of quantities.
                $estimate = $this->estimates->fromFinalJson($result->refresh(), $reviewer, $job);

                // 4. Whoever signed the review is the reviewer of record on the job.
                $this->recordReviewer($job, $reviewer);

                $result->recordHistory(
                    'review_completed',
                    "Review completed: job “{$job->name}” and estimate {$estimate->number} created",
                    to: $estimate->number,
                    meta: [
                        'job_id' => $job->id,
                        'estimate_id' => $estimate->id,
                        'upload_id' => $result->upload_id,
                        'ai_result_id' => $result->id,
                    ],
                );

                return $job;
            });
        } catch (RuntimeException $e) {
            // Expected refusals (nothing approved, nothing to price) are the
            // reviewer's business, not an error to swallow.
            Log::info('Review completion refused', [
                'ai_result_id' => $result->id,
                'reason' => $e->getMessage(),
            ]);

            throw $e;
        } catch (Throwable $e) {
            Log::error('Review completion failed and was rolled back', [
                'ai_result_id' => $result->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            throw $e;
        }
    }

    /**
     * Puts the signing reviewer on the job, unless that role is already held.
     *
     * A real assignment rather than a decoration: the audit trail and the estimator
     * notification both key off who holds a role.
     */
    private function recordReviewer(Job $job, User $reviewer): void
    {
        $existing = $job->activeAssignments()
            ->where('role', JobAssignment::ROLE_REVIEWER)
            ->first();

        if ($existing) {
            return;
        }

        $assignment = $job->assignments()->create([
            'user_id' => $reviewer->id,
            'role' => JobAssignment::ROLE_REVIEWER,
            'name' => $reviewer->name,
            'notes' => 'Signed off the AI takeoff review.',
            'assigned_by' => $reviewer->id,
            'assigned_at' => now(),
        ]);

        $job->recordActivity(
            'assigned',
            "{$reviewer->name} assigned as Reviewer after signing off the takeoff",
            ['assignment_id' => $assignment->id, 'role' => $assignment->role],
        );
    }
}
