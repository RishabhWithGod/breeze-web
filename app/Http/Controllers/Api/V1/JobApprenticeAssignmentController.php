<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Foreman;
use App\Models\Job;
use App\Services\Mobile\ElectricianJobAccess;
use App\Services\Scheduling\ApprenticeAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Putting an apprentice under a journeyman on a job — a foreman's own call,
 * made from their Job Detail screen. The validation and persistence are
 * shared with the web app's read/manage view via
 * {@see ApprenticeAssignmentService}; only "may this account do it" differs
 * here — a foreman's crew-register role and their own staffing on the job,
 * not the web app's manager-role/ownership check (`JobSchedulePolicy`),
 * which doesn't apply to a mobile crew account at all.
 */
class JobApprenticeAssignmentController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly ElectricianJobAccess $access,
        private readonly ApprenticeAssignmentService $assignments,
    ) {}

    public function store(Request $request, Job $job): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $actingForeman = $request->user()->foreman;
        abort_unless(
            $actingForeman?->role === Foreman::ROLE_FOREMAN,
            403,
            'Only a foreman can assign an apprentice.',
        );

        if ($job->isLocked()) {
            return $this->fail('This job is already completed and can no longer be changed.', 409);
        }

        $data = $request->validate([
            'journeyman_id' => ['required', 'integer'],
            'apprentice_id' => ['required', 'integer'],
        ]);

        // Throws a `ValidationException` on an unstaffed journeyman or an
        // apprentice off that journeyman's team — rendered as the same 422
        // envelope every other mobile validation failure returns (see
        // `bootstrap/app.php`'s exception handler), no separate catch needed.
        $assignment = $this->assignments->assign(
            $job,
            $data['journeyman_id'],
            $data['apprentice_id'],
            $request->user(),
        );
        $assignment->load(['journeyman:id,name', 'apprentice:id,name']);

        return $this->created([
            'id' => $assignment->id,
            'journeymanId' => $assignment->journeyman_id,
            'journeymanName' => $assignment->journeyman->name,
            'apprenticeId' => $assignment->apprentice_id,
            'apprenticeName' => $assignment->apprentice->name,
        ], "{$assignment->apprentice->name} is assigned under {$assignment->journeyman->name}.");
    }
}
