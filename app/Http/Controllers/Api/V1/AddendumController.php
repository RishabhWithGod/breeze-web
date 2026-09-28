<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Estimate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Where a project's addenda are listed — mobile's counterpart to web's own
 * `AddendumController::index()`. Read-only, same as web: an addendum is
 * *created* through the same upload → AI takeoff → review flow every
 * estimate is (`Api\V1\UploadController::store`'s own `addendum_for_
 * estimate_id` field, already wired identically to web), never here. This
 * only lists what already exists for a given project — the project list
 * itself is `GET /api/v1/projects`, not duplicated here.
 */
class AddendumController extends Controller
{
    use ApiResponses;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project_id' => [
                'nullable', 'integer',
                Rule::exists('projects', 'id')->where('user_id', $request->user()->id),
            ],
        ]);

        $projectId = $data['project_id'] ?? null;

        if ($projectId === null) {
            return $this->ok(['originals' => []]);
        }

        $originals = Estimate::query()
            ->where('user_id', $request->user()->id)
            ->where('project_id', $projectId)
            ->where('kind', Estimate::KIND_STANDALONE)
            ->with(['addenda' => fn ($query) => $query->where('user_id', $request->user()->id)])
            ->orderByDesc('issued_on')
            ->get()
            ->map(fn (Estimate $estimate) => $this->present($estimate, $estimate->addenda))
            ->values();

        return $this->ok(['originals' => $originals]);
    }

    /** @return array<string, mixed> */
    private function present(Estimate $estimate, iterable $addenda = []): array
    {
        return [
            'id' => $estimate->id,
            'number' => $estimate->number,
            'addendumNumber' => $estimate->addendum_number,
            'addendumName' => $estimate->addendum_name,
            'status' => $estimate->status,
            'amount' => (float) $estimate->amount,
            'createdAt' => $estimate->created_at?->toISOString(),
            'addenda' => collect($addenda)->map(fn (Estimate $addendum) => $this->present($addendum))->values(),
        ];
    }
}
