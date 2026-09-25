<?php

namespace App\Services\Ai;

use RuntimeException;
use Throwable;

/** Raised whenever the AI service is unreachable, misconfigured or rejects us. */
class AiApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $body = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self(
            'The AI takeoff service is not configured. Set AI_API_BASE_URL (and AI_API_KEY) in .env.'
        );
    }

    /**
     * Static takeoff mode is on and no stored dataset matches the uploaded
     * drawing. Worded for the reviewer, not the mechanism: this reads
     * standalone on the processing screen (see `Processing.tsx`), so it must
     * never mention how the match is made internally.
     */
    public static function staticDatasetNotFound(): self
    {
        return new self("This drawing hasn't been synced yet. Please upload a drawing that has already been synced, or contact your administrator to sync it.");
    }

    public static function badStatus(string $action, int $status, ?string $body): self
    {
        return new self(
            "The AI takeoff service returned HTTP {$status} while {$action}.",
            $status,
            $body,
        );
    }

    public static function unusablePayload(string $why): self
    {
        return new self("The AI takeoff service returned a response this app cannot use: {$why}");
    }
}
