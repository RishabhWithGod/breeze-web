<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\JobDetailResource;
use App\Models\ChangeOrder;
use App\Models\Job;
use App\Models\JobApprenticeAssignment;
use App\Models\JobAttachment;
use App\Models\JobNote;
use App\Models\JobSchedule;
use App\Models\TimeEntry;
use App\Policies\InvoicePolicy;
use App\Policies\JobSchedulePolicy;
use App\Services\ChangeOrders\ChangeOrderAccess;
use App\Services\JobCosting\JobCostSummary;
use App\Services\Mobile\ElectricianJobAccess;
use App\Services\Mobile\JobFieldMaterialsSummary;
use App\Services\Scheduling\ApprenticeAssignmentService;
use App\Models\JobFieldMaterial;
use App\Services\Billing\FieldMaterialEstimateSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The office's Job Detail for the app — mobile's counterpart to web's own
 * `JobController::show()` and the panels on that page (status, notes,
 * documents, apprentices, delete), each calling the same model methods and
 * policies web does. Distinct from `JobController` (mobile), which is the
 * field crew's own view of a job they are staffed on.
 */
class OfficeJobController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly JobCostSummary $costSummary,
        private readonly JobSchedulePolicy $schedulePolicy,
        private readonly ChangeOrderAccess $changeOrders,
        private readonly ElectricianJobAccess $jobAccess,
    ) {}

    /**
     * Reading: the company's managers, or anyone the field rules staff on the
     * job — a foreman or journeyman opens the same screen, read-only.
     */
    private function mayView(Request $request, Job $job): void
    {
        abort_unless(
            $request->user()->can('view', $job) && ($this->changeOrders->isManager($request->user()) || $this->jobAccess->canAccess($request->user(), $job)),
            403,
            'You are not staffed on this job.',
        );
    }

    /**
     * Writing is the office's: web's `JobPolicy` lets anyone in the company act on any of its
     * jobs, which is right for web users but not for a crew member on the phone.
     */
    private function mayManage(Request $request, Job $job): void
    {
        abort_unless(
            $request->user()->can('update', $job) && $this->changeOrders->isManager($request->user()),
            403,
            'Only a manager can change a job.',
        );
    }

    /** Web's `JobShow` props. */
    public function show(Request $request, Job $job): JsonResponse
    {
        $this->mayView($request, $job);

        $job->load([
            'project',
            'team:id,name',
            'teamMembers',
            'estimates',
            'tasks' => fn ($query) => $query
                ->with(
                    'foreman:id,name,initials',
                    'supervisor:id,name,initials',
                    'crew:id,name,initials,role',
                    'assignments.member:id,name,initials',
                    'comments.author:id,name',
                    'attachments.uploader:id,name,role',
                    'estimateItems.comments.author:id,name',
                    'estimateItems.attachments.uploader:id,name,role',
                )
                ->withCount('estimateItems')
                ->orderBy('position')
                ->orderBy('id'),
            'notes.author',
            'attachments.uploader',
            'activities.actor',
            'statusChanges.actor',
            'assignments.assigner',
            'aiResult',
            'invoices:id,job_id',
        ]);

        $user = $request->user();
        $permissions = app(\App\Services\Access\Permissions::class);
        $canViewTimeCosts = (bool) $user->can('viewJobCosts', TimeEntry::class);
        $jobCosting = $this->costSummary->for($job);

        return $this->ok([
            'job' => (new JobDetailResource($job))->resolve(),
            'canViewTimeCosts' => $canViewTimeCosts,
            'jobCosting' => $canViewTimeCosts ? $jobCosting : JobCostSummary::redact($jobCosting),
            'canPlanWork' => $this->schedulePolicy
                ->createTask($user, $job->schedule ?? new JobSchedule(['job_id' => $job->id]))
                && $permissions->allows($user, 'tasks.create'),
            'canManageApprentices' => $this->schedulePolicy->assignApprentice($user, $job),
            'canCreateInvoice' => app(InvoicePolicy::class)->create($user) && $permissions->allows($user, 'billing.create'),
            // The role's Roles & Permissions apply on top of ownership, as on web's JobShow.
            'canEdit' => ! $job->isLocked() && $this->canManage($request, $job) && $permissions->allows($user, 'jobs.edit'),
            'canDelete' => ! $job->isLocked() && $this->canManage($request, $job) && $permissions->allows($user, 'jobs.delete'),
            'changeOrders' => $this->changeOrdersFor($request, $job),
            'fieldMaterials' => app(JobFieldMaterialsSummary::class)->for($job),
            'apprenticeAssignments' => $job->apprenticeAssignments()
                ->with(['journeyman:id,name', 'apprentice:id,name'])
                ->get()
                ->map(fn (JobApprenticeAssignment $a) => [
                    'id' => $a->id,
                    'journeymanName' => $a->journeyman->name,
                    'apprenticeName' => $a->apprentice->name,
                ])->values(),
            'statuses' => Job::STATUSES,
        ]);
    }

    private function canManage(Request $request, Job $job): bool
    {
        return $request->user()->can('update', $job) && $this->changeOrders->isManager($request->user());
    }

    /** Web's `JobController::changeStatus()`. */
    public function changeStatus(Request $request, Job $job): JsonResponse
    {
        $this->mayManage($request, $job);
        $job->assertNotLocked();

        $validated = $request->validate(['status' => ['required', Rule::in(Job::STATUSES)]]);
        $job->changeStatus($validated['status']);

        return $this->ok(['status' => $job->fresh()->status], "Status set to {$validated['status']}.");
    }

    /** Web's `JobFieldMaterialController::update()` — correct what the crew added, while it is pending. */
    public function updateFieldMaterial(Request $request, Job $job, JobFieldMaterial $material): JsonResponse
    {
        $this->authoriseFieldMaterial($request, $job, $material);

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

        return $this->ok(null, 'Entry updated.');
    }

    /** Web's `approve()` — onto the estimate and billing. */
    public function approveFieldMaterial(Request $request, Job $job, JobFieldMaterial $material, FieldMaterialEstimateSync $sync): JsonResponse
    {
        $this->authoriseFieldMaterial($request, $job, $material);

        $sync->approve($material, $request->user());
        $job->recordActivity('field_material_approved', "Added “{$material->description}” to the estimate");

        return $this->ok(null, 'Added to the estimate and billing.');
    }

    public function destroyFieldMaterial(Request $request, Job $job, JobFieldMaterial $material): JsonResponse
    {
        $this->authoriseFieldMaterial($request, $job, $material);

        $material->delete();

        return $this->ok(null, 'Entry removed.');
    }

    private function authoriseFieldMaterial(Request $request, Job $job, JobFieldMaterial $material): void
    {
        abort_unless($material->job_id === $job->id && $material->isAdded(), 404);
        abort_unless($this->changeOrders->isManager($request->user()), 403);
        abort_if($job->isLocked(), 409, 'This job is already completed and can no longer be changed.');
        abort_if($material->isApproved(), 409, 'This entry is already on the estimate.');
    }

    /** Web's `JobController::archive()` — hides the job from pickers, keeps it listed. */
    public function archive(Request $request, Job $job): JsonResponse
    {
        $this->mayManage($request, $job);

        $job->update(['archived_at' => now()]);
        $job->recordActivity('archived', 'Job archived');

        return $this->ok(null, "“{$job->name}” was archived.");
    }

    public function unarchive(Request $request, Job $job): JsonResponse
    {
        $this->mayManage($request, $job);

        $job->update(['archived_at' => null]);
        $job->recordActivity('unarchived', 'Job restored from the archive');

        return $this->ok(null, "“{$job->name}” was moved out of the archive.");
    }

    /** Web's `JobController::restore()` — Undo for a delete. */
    public function restore(Request $request, int $job): JsonResponse
    {
        $trashed = Job::onlyTrashed()->ownedBy($request->user())->findOrFail($job);
        $this->mayManage($request, $trashed);

        $trashed->restore();
        $trashed->recordActivity('restored', 'Job restored');

        return $this->ok(['id' => $trashed->id], "“{$trashed->name}” was restored.");
    }

    /**
     * Web's `JobController::bulk()` — delete or set a status on several jobs
     * at once. A completed job is locked, so it is skipped and the rest still go through.
     */
    public function bulk(Request $request): JsonResponse
    {
        abort_unless($this->changeOrders->isManager($request->user()), 403, 'Only a manager can change a job.');

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['delete', 'status'])],
            'status' => ['nullable', Rule::in(Job::STATUSES), 'required_if:action,status'],
        ]);

        $jobs = Job::whereIn('id', $data['ids'])->ownedBy($request->user())->get();
        [$locked, $jobs] = $jobs->partition(fn (Job $job) => $job->isLocked());

        foreach ($jobs as $job) {
            if ($data['action'] === 'delete') {
                $job->recordActivity('deleted', 'Job deleted in a bulk action');
                $job->delete();
            } else {
                $job->changeStatus($data['status']);
            }
        }

        $note = $locked->isNotEmpty() ? " {$locked->count()} completed job(s) were left as they are." : '';

        return $this->ok(
            ['count' => $jobs->count(), 'skipped' => $locked->count(), 'ids' => $jobs->pluck('id')->all()],
            $jobs->count().' job(s) '.($data['action'] === 'delete' ? 'deleted.' : "set to {$data['status']}.").$note,
        );
    }

    /** Web's `JobController::destroy()`. */
    public function destroy(Request $request, Job $job): JsonResponse
    {
        $this->mayManage($request, $job);
        $job->assertNotLocked();

        $job->recordActivity('deleted', 'Job deleted');
        $job->delete();

        return $this->ok(null, "“{$job->name}” was deleted.");
    }

    /** Web's `JobNoteController::store()`. */
    public function storeNote(Request $request, Job $job): JsonResponse
    {
        $this->mayManage($request, $job);
        $job->assertNotLocked();

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:2000'],
        ], ['body.required' => 'Write something before saving the note']);

        $job->notes()->create(['user_id' => $request->user()->id, 'body' => $validated['body']]);
        $job->recordActivity('note_added', 'Note added');

        return $this->created(null, 'Note added.');
    }

    /** Web's `JobNoteController::destroy()`. */
    public function destroyNote(Request $request, Job $job, JobNote $note): JsonResponse
    {
        $this->mayManage($request, $job);
        abort_unless($note->job_id === $job->id, 404);
        $job->assertNotLocked();

        $note->delete();
        $job->recordActivity('note_deleted', 'Note deleted');

        return $this->ok(null, 'Note deleted.');
    }

    /** Web's `JobAttachmentController::store()`. */
    public function storeAttachment(Request $request, Job $job): JsonResponse
    {
        $this->mayManage($request, $job);
        $job->assertNotLocked();

        $validated = $request->validate([
            'file' => ['required', Rule::file()->extensions(config('documents.extensions')), 'max:20480'],
        ], [
            'file.required' => 'Choose a file to upload',
            'file.extensions' => 'Unsupported file type — accepted types are '
                .implode(', ', array_map(fn (string $ext) => ".{$ext}", config('documents.extensions'))),
            'file.max' => 'Files must be 20 MB or smaller',
        ]);

        $file = $validated['file'];
        $attachment = $job->attachments()->create([
            'user_id' => $request->user()->id,
            'name' => $file->getClientOriginalName(),
            'path' => $file->store("job-attachments/{$job->id}", 'local'),
            'size' => $file->getSize(),
            'mime' => $file->getClientMimeType(),
        ]);

        $job->recordActivity('attachment_added', "Attachment “{$attachment->name}” uploaded", [
            'attachment_id' => $attachment->id,
        ]);

        return $this->created(null, "“{$attachment->name}” was uploaded.");
    }

    public function downloadAttachment(Request $request, Job $job, JobAttachment $attachment): StreamedResponse
    {
        $this->mayView($request, $job);
        abort_unless($attachment->job_id === $job->id, 404);

        return response()->streamDownload(
            fn () => print (Storage::disk('local')->get($attachment->path)),
            $attachment->name,
            ['Content-Type' => $attachment->mime ?? 'application/octet-stream'],
        );
    }

    /** Web's `JobAttachmentController::destroy()`. */
    public function destroyAttachment(Request $request, Job $job, JobAttachment $attachment): JsonResponse
    {
        $this->mayManage($request, $job);
        abort_unless($attachment->job_id === $job->id, 404);
        $job->assertNotLocked();

        $name = $attachment->name;
        $attachment->deleteWithFile();
        $job->recordActivity('attachment_deleted', "Attachment “{$name}” deleted");

        return $this->ok(null, "“{$name}” was deleted.");
    }

    /** Web's `JobApprenticeAssignmentController::destroy()`. */
    public function destroyApprentice(Request $request, Job $job, JobApprenticeAssignment $assignment): JsonResponse
    {
        abort_unless($this->schedulePolicy->assignApprentice($request->user(), $job), 403);
        abort_unless($assignment->job_id === $job->id, 404);
        $job->assertNotLocked();

        $name = $assignment->apprentice->name;
        app(ApprenticeAssignmentService::class)->unassign($job, $assignment);

        return $this->ok(null, "{$name} removed from the job.");
    }

    /** @return array<string, mixed>|null */
    private function changeOrdersFor(Request $request, Job $job): ?array
    {
        $user = $request->user();

        if (! $this->changeOrders->canUse($user)) {
            return null;
        }

        return [
            'canRaise' => $this->changeOrders->canRaiseFor($user, $job),
            'items' => $this->changeOrders->visible(ChangeOrder::query(), $user)->where('job_id', $job->id)->orderByDesc('number')->get()
                ->map(fn (ChangeOrder $co) => [
                    'id' => $co->id,
                    'label' => $co->label(),
                    'description' => $co->description,
                    'source' => $co->source,
                    'status' => $co->status,
                    'amount' => (float) $co->sell_total,
                ])->all(),
        ];
    }
}
