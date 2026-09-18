<?php

namespace App\Services\Scheduling;

use App\Models\Job;
use App\Models\JobApprenticeAssignment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Putting an apprentice under a journeyman on a job — the one place this
 * actually happens, so a web manager viewing the result and a mobile
 * foreman making the assignment are guaranteed to see the same rule
 * enforced the same way. `JobApprenticeAssignmentController` (web, read/
 * manage for a manager) and `Api\V1\JobApprenticeAssignmentController`
 * (mobile, where a foreman actually makes the assignment) both call this;
 * neither re-implements the validation.
 */
class ApprenticeAssignmentService
{
    /**
     * @throws ValidationException when `journeymanId` isn't actually staffed
     *                              on this job, or `apprenticeId` isn't on
     *                              that journeyman's own team — never
     *                              trusted at face value from either caller.
     */
    public function assign(Job $job, int $journeymanId, int $apprenticeId, ?User $assignedBy = null): JobApprenticeAssignment
    {
        // Only a journeyman actually staffed on this job's own tasks is a
        // valid choice — the same set `assignedJourneymen()` offers the
        // picker on both surfaces.
        $journeyman = $job->assignedJourneymen()->firstWhere('id', $journeymanId);
        if ($journeyman === null) {
            throw ValidationException::withMessages([
                'journeyman_id' => 'That journeyman is not staffed on this job.',
            ]);
        }

        // And only an apprentice on that journeyman's own team — the crew
        // hierarchy the picker itself narrows to.
        $apprentice = $journeyman->teamApprentices()->find($apprenticeId);
        if ($apprentice === null) {
            throw ValidationException::withMessages([
                'apprentice_id' => 'That apprentice is not on this journeyman\'s team.',
            ]);
        }

        $assignment = $job->apprenticeAssignments()->updateOrCreate(
            ['apprentice_id' => $apprentice->id],
            ['journeyman_id' => $journeyman->id, 'assigned_by' => $assignedBy?->id],
        );

        $job->recordActivity(
            'apprentice_assigned',
            "{$apprentice->name} assigned to the job under {$journeyman->name}",
            ['assignment_id' => $assignment->id, 'journeyman_id' => $journeyman->id, 'apprentice_id' => $apprentice->id],
        );

        return $assignment;
    }

    public function unassign(Job $job, JobApprenticeAssignment $assignment): void
    {
        $assignment->delete();

        $job->recordActivity(
            'apprentice_unassigned',
            "{$assignment->apprentice->name} removed from the job",
            ['assignment_id' => $assignment->id],
        );
    }
}
