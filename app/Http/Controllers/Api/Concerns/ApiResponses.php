<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\JsonResponse;

/**
 * One JSON envelope for every mobile endpoint: `{success, message, data}` on
 * the way out, matched by a correct HTTP status code — never a 200 for a
 * failed operation, and never a fabricated success payload.
 */
trait ApiResponses
{
    protected function ok(mixed $data = null, string $message = '', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function created(mixed $data = null, string $message = ''): JsonResponse
    {
        return $this->ok($data, $message, 201);
    }

    protected function fail(string $message, int $status = 400, mixed $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }
}
