<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\JobFieldMaterial;
use App\Services\Billing\FieldMaterialEstimateSync;
use App\Services\ChangeOrders\ChangeOrderAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The office's side of what the crew added on site (material or labor): correct it while it is
 * pending, approve it onto the estimate and billing, or remove it. Once approved it is on the
 * estimate and is no longer changed here.
 */
class JobFieldMaterialController extends Controller
{
    public function __construct(
        private readonly ChangeOrderAccess $access,
        private readonly FieldMaterialEstimateSync $sync,
    ) {}

    public function update(Request $request, Job $job, JobFieldMaterial $material): RedirectResponse
    {
        $this->authorizeEntry($request, $job, $material);

        $data = $request->validate([
            'kind' => ['required', Rule::in([JobFieldMaterial::MATERIAL, JobFieldMaterial::LABOR])],
            'description' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $material->update([
            'kind' => $data['kind'],
            'description' => trim($data['description']),
            'unit' => $data['kind'] === JobFieldMaterial::LABOR ? 'hr' : ($material->unit ?: 'ea'),
            'actual_quantity' => $data['quantity'],
            'unit_price' => $data['unit_price'],
            'total' => round((float) $data['quantity'] * (float) $data['unit_price'], 2),
            'reason' => filled($data['reason'] ?? null) ? trim($data['reason']) : null,
        ]);

        return back()->with('success', 'Entry updated.');
    }

    public function approve(Request $request, Job $job, JobFieldMaterial $material): RedirectResponse
    {
        $this->authorizeEntry($request, $job, $material);

        $this->sync->approve($material, $request->user());
        $job->recordActivity('field_material_approved', "Added “{$material->description}” to the estimate");

        return back()->with('success', 'Added to the estimate and billing.');
    }

    public function destroy(Request $request, Job $job, JobFieldMaterial $material): RedirectResponse
    {
        $this->authorizeEntry($request, $job, $material);

        $material->delete();

        return back()->with('warning', 'Entry removed.');
    }

    private function authorizeEntry(Request $request, Job $job, JobFieldMaterial $material): void
    {
        abort_unless($material->job_id === $job->id && $material->isAdded(), 404);
        abort_unless($this->access->isManager($request->user()), 403);
        abort_if($job->isLocked(), 409, 'This job is already completed and can no longer be changed.');
        abort_if($material->isApproved(), 409, 'This entry is already on the estimate.');
    }
}
