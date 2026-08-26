<?php

namespace App\Services\Takeoff;

use App\Models\AiResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Signing off a review finalises the reviewed document only.
 *
 * final_response.json + the final symbol table + the bill of quantities are built
 * from the approved symbols, inside a transaction so a failure leaves the takeoff
 * exactly as it was. The job and its estimate are deliberately not raised here —
 * a reviewer signs off the numbers, then raises the job explicitly (with whatever
 * name, client, budget and staffing they choose) from the review summary screen.
 *
 * Every step is idempotent: re-finalising a reopened takeoff just rebuilds the
 * document.
 */
class CompleteReview
{
    public function __construct(private readonly FinalJsonBuilder $finalJson) {}

    /**
     * @throws RuntimeException Nothing approved yet.
     * @throws Throwable Anything unexpected, after the transaction rolls back.
     */
    public function handle(AiResult $result, User $reviewer): AiResult
    {
        try {
            return DB::transaction(function () use ($result, $reviewer): AiResult {
                $this->finalJson->build($result, $reviewer);

                return $result->refresh();
            });
        } catch (RuntimeException $e) {
            // Expected refusal (nothing approved yet) is the reviewer's business,
            // not an error to swallow.
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
}
