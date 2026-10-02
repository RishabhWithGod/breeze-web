<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\EstimateItem;
use App\Models\Job;
use App\Models\JobFieldMaterial;
use App\Models\JobTask;
use App\Models\User;
use App\Policies\JobSchedulePolicy;
use App\Services\Mobile\ElectricianJobAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Material and Work Changes — what was actually used on a job against what was
 * planned, plus anything the crew added on site. Field entries are kept apart
 * from approved change orders (those are raised and priced separately) and a
 * quantity that runs over plan, or a material that was never planned, is
 * flagged so the office can review it as a possible billable exception.
 */
class JobMaterialController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly ElectricianJobAccess $access,
        private readonly JobSchedulePolicy $policy,
    ) {}

    public function index(Request $request, Job $job): JsonResponse
    {
        $this->authorizeJob($request, $job);

        return $this->ok($this->payload($request->user(), $job));
    }

    /**
     * One write for everything staged on the phone: actual quantities against
     * planned lines, and materials added on site. All or nothing, and safe to
     * replay — a planned line has one actual that is simply overwritten, and
     * an added material carries the phone's own `client_key`.
     */
    public function submit(Request $request, Job $job): JsonResponse
    {
        $this->authorizeJob($request, $job);
        abort_if($job->isLocked(), 409, 'This job is completed and locked.');
        abort_if(
            $job->isReadyForReview() && ! $request->user()->hasForemanAuthority(),
            409,
            'This job has been submitted for review — wait for your foreman to act on it.',
        );

        $data = $request->validate([
            'materials' => ['nullable', 'array', 'max:300'],
            'materials.*.estimate_item_id' => ['required', 'integer'],
            'materials.*.actual_quantity' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'materials.*.reason' => ['nullable', 'string', 'max:1000'],
            'added' => ['nullable', 'array', 'max:100'],
            'added.*.client_key' => ['required', 'string', 'max:64'],
            'added.*.description' => ['required', 'string', 'max:255'],
            'added.*.unit' => ['nullable', 'string', 'max:24'],
            'added.*.actual_quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'added.*.reason' => ['nullable', 'string', 'max:1000'],
            'added.*.price_book_item_id' => ['nullable', 'integer'],
            'added.*.type' => ['nullable', Rule::in([JobFieldMaterial::MATERIAL, JobFieldMaterial::LABOR])],
            // Unit price (material) or hourly rate (labor). Only a foreman or manager prices.
            'added.*.price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'added.*.job_task_id' => ['nullable', 'integer', Rule::exists('job_tasks', 'id')->where('job_id', $job->id)],
        ], [
            'added.*.description.required' => 'Name every material you add.',
            'added.*.actual_quantity.gt' => 'An added material needs a quantity above zero.',
        ]);

        $user = $request->user();
        $itemIds = collect($data['materials'] ?? [])->pluck('estimate_item_id')->unique();
        $items = EstimateItem::query()
            ->whereIn('id', $itemIds)
            ->whereIn('job_task_id', $job->tasks()->select('id'))
            ->with('task')
            ->get()
            ->keyBy('id');

        foreach ($itemIds as $id) {
            abort_unless($items->has($id), 422, 'One of those lines is not on this job.');
            abort_unless(
                $this->policy->completeTask($user, $items[$id]->task),
                403,
                'You are not on the task that line belongs to.',
            );
        }

        DB::transaction(function () use ($data, $items, $job, $user) {
            foreach ($data['materials'] ?? [] as $row) {
                $item = $items[(int) $row['estimate_item_id']];

                // An empty quantity clears the report, back to "not reported yet".
                if (! array_key_exists('actual_quantity', $row) || $row['actual_quantity'] === null || $row['actual_quantity'] === '') {
                    JobFieldMaterial::where('estimate_item_id', $item->id)->delete();

                    continue;
                }

                JobFieldMaterial::updateOrCreate(
                    ['estimate_item_id' => $item->id],
                    [
                        'job_id' => $job->id,
                        'job_task_id' => $item->job_task_id,
                        'description' => $item->description,
                        'unit' => $item->unit,
                        'actual_quantity' => $row['actual_quantity'],
                        'reason' => filled($row['reason'] ?? null) ? trim($row['reason']) : null,
                        'user_id' => $user->id,
                    ],
                );
            }

            $canPrice = $user->hasForemanAuthority();

            foreach ($data['added'] ?? [] as $row) {
                $kind = $row['type'] ?? JobFieldMaterial::MATERIAL;
                $price = $canPrice ? (float) ($row['price'] ?? 0) : 0.0;
                $isLabor = $kind === JobFieldMaterial::LABOR;

                JobFieldMaterial::firstOrCreate(
                    ['job_id' => $job->id, 'client_key' => $row['client_key']],
                    [
                        'job_task_id' => $row['job_task_id'] ?? null,
                        'price_book_item_id' => $row['price_book_item_id'] ?? null,
                        'description' => trim($row['description']),
                        'kind' => $kind,
                        'unit' => $isLabor ? 'hr' : (filled($row['unit'] ?? null) ? trim($row['unit']) : null),
                        'actual_quantity' => $row['actual_quantity'],
                        'unit_price' => $price,
                        // Worked out here, never trusted from the phone.
                        'total' => round((float) $row['actual_quantity'] * $price, 2),
                        'reason' => filled($row['reason'] ?? null) ? trim($row['reason']) : null,
                        'user_id' => $user->id,
                    ],
                );
            }

            $changed = count($data['materials'] ?? []) + count($data['added'] ?? []);
            if ($changed > 0) {
                $job->recordActivity('field_materials_updated', "Field materials updated ({$changed} ".str('entry')->plural($changed).')');
            }
        });

        return $this->ok($this->payload($user, $job), 'Update submitted.');
    }

    public function destroy(Request $request, Job $job, JobFieldMaterial $material): JsonResponse
    {
        $this->authorizeJob($request, $job);
        abort_unless($material->job_id === $job->id && $material->isAdded(), 404);
        abort_if($job->isLocked(), 409, 'This job is completed and locked.');
        abort_if($material->isApproved(), 409, 'This is already on the estimate.');
        abort_unless(
            $material->user_id === $request->user()->id || $request->user()->hasForemanAuthority(),
            403,
            'Only whoever added a material, or a foreman, can remove it.',
        );

        $material->delete();

        return $this->ok($this->payload($request->user(), $job), 'Material removed.');
    }

    private function authorizeJob(Request $request, Job $job): void
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');
    }

    /** @return array<string, mixed> */
    private function payload(User $user, Job $job): array
    {
        $tasks = $job->tasks()->get(['id', 'title'])->keyBy('id');
        $actuals = $job->fieldMaterials()->whereNotNull('estimate_item_id')->get()->keyBy('estimate_item_id');

        $planned = EstimateItem::query()
            ->whereIn('job_task_id', $tasks->keys())
            ->where('category', '!=', EstimateItem::CATEGORY_LABOR)
            ->with('task')
            ->orderBy('job_task_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(function (EstimateItem $item) use ($tasks, $actuals, $user) {
                $entry = $actuals->get($item->id);
                $planned = (float) $item->quantity;
                $actual = $entry === null ? null : (float) $entry->actual_quantity;

                return [
                    'id' => $item->id,
                    'taskId' => $item->job_task_id,
                    'taskTitle' => $tasks[$item->job_task_id]->title ?? '',
                    'description' => $item->description,
                    'category' => $item->category,
                    'unit' => $item->unit,
                    'plannedQty' => $planned,
                    'actualQty' => $actual,
                    'reason' => $entry?->reason,
                    'exception' => $this->exception($planned, $actual),
                    'canEdit' => $item->task instanceof JobTask && $this->policy->completeTask($user, $item->task),
                ];
            })->values()->all();

        $added = $job->fieldMaterials()->whereNull('estimate_item_id')->with('reporter:id,name')
            ->orderBy('id')->get()
            ->map(fn (JobFieldMaterial $m) => [
                'id' => $m->id,
                'clientKey' => $m->client_key,
                'type' => $m->kind,
                'description' => $m->description,
                'unit' => $m->unit,
                'actualQty' => (float) $m->actual_quantity,
                'status' => $m->status,
                ...($user->hasForemanAuthority() ? [
                    'price' => (float) $m->unit_price,
                    'total' => (float) $m->total,
                ] : []),
                'reason' => $m->reason,
                'taskId' => $m->job_task_id,
                'addedBy' => $m->reporter?->name,
                'mine' => $m->user_id === $user->id,
                'createdAt' => $m->created_at?->toISOString(),
            ])->all();

        $locked = $job->isLocked()
            || ($job->isReadyForReview() && ! $user->hasForemanAuthority());

        return [
            'materials' => $planned,
            'added' => $added,
            'canPrice' => $user->hasForemanAuthority(),
            'canEdit' => ! $locked,
            'lockedReason' => $job->isLocked()
                ? 'This job is completed and locked.'
                : ($locked ? 'This job has been submitted for review.' : null),
            'needsReviewCount' => collect($planned)->whereIn('exception', ['over'])->count() + count($added),
        ];
    }

    /** "over" and added materials are the billable exceptions the office reviews; "under" is informational. */
    private function exception(float $planned, ?float $actual): ?string
    {
        if ($actual === null) {
            return null;
        }
        if ($actual > $planned) {
            return 'over';
        }

        return $actual < $planned ? 'under' : null;
    }
}
