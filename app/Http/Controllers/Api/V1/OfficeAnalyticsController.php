<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\AnalyticsSetting;
use App\Models\Job;
use App\Models\JobCostEntry;
use App\Models\TimeEntry;
use App\Policies\JobCostingPolicy;
use App\Services\Billing\ActualCostInvoiceSync;
use App\Services\Export\JobCostingExporter;
use App\Services\JobCosting\AnalyticsDashboard;
use App\Services\JobCosting\JobCostOverrunNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Analytics and Job Costing for the phone — the web's own data and the same
 * rules, from the same service (`AnalyticsDashboard`), so the two always show
 * the same numbers. Dollar figures are left out for a role without cost access.
 */
class OfficeAnalyticsController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly AnalyticsDashboard $dashboard,
        private readonly JobCostingExporter $exporter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->ok($this->dashboard->build($request->user(), $this->dashboard->filters($request)));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        abort_unless(app(JobCostingPolicy::class)->manage($request->user()), 403);

        $validated = $request->validate([
            'low_margin_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'hours_warning_pct' => ['required', 'numeric', 'min:1', 'max:100'],
            'stale_timer_hours' => ['required', 'integer', 'min:1', 'max:72'],
            'overdue_grace_days' => ['required', 'integer', 'min:0', 'max:90'],
        ]);

        $settings = AnalyticsSetting::current();
        $settings->update([...$validated, 'updated_by' => $request->user()->id]);

        return $this->ok(
            $settings->refresh()->only(['low_margin_pct', 'hours_warning_pct', 'stale_timer_hours', 'overdue_grace_days']),
            'Analytics thresholds saved.',
        );
    }

    public function export(Request $request, string $format): StreamedResponse|BinaryFileResponse
    {
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);
        abort_unless($request->user()->can('viewJobCosts', TimeEntry::class), 403);

        $filters = $this->dashboard->filters($request);
        $rows = $this->dashboard->rowsFor($this->dashboard->filteredJobs($filters), $filters);
        $filename = 'job-costing-'.now()->toDateString();

        if ($format === 'csv') {
            $contents = $this->exporter->csv($rows);

            return ResponseFactory::streamDownload(fn () => print $contents, "{$filename}.csv", ['Content-Type' => 'text/csv']);
        }

        return ResponseFactory::download($this->exporter->xlsx($rows), "{$filename}.xlsx")->deleteFileAfterSend();
    }

    /** One job's Job Costing screen — all-time unless a range is given. */
    public function show(Request $request, Job $job): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return $this->ok($this->dashboard->jobDetail($request->user(), $job, $data));
    }

    public function storeCostEntry(Request $request, Job $job, JobCostOverrunNotifier $notifier, ActualCostInvoiceSync $invoiceSync): JsonResponse
    {
        abort_unless(app(JobCostingPolicy::class)->manage($request->user()), 403);
        $job->assertNotLocked();

        $validated = $request->validate([
            'category' => ['required', Rule::in(JobCostEntry::CATEGORIES)],
            'description' => ['required', 'string', 'max:200'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'amount' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'incurred_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $job->costEntries()->create([...$validated, 'recorded_by' => $request->user()->id]);

        $notifier->checkAndNotify($job);
        $invoiceSync->sync($job);

        return $this->created($this->dashboard->jobDetail($request->user(), $job->fresh(), []), "\"{$validated['description']}\" recorded.");
    }

    public function destroyCostEntry(Request $request, Job $job, JobCostEntry $entry, ActualCostInvoiceSync $invoiceSync): JsonResponse
    {
        abort_unless(app(JobCostingPolicy::class)->manage($request->user()), 403);
        abort_unless($entry->job_id === $job->id, 404);
        $job->assertNotLocked();

        $description = $entry->description;
        $entry->delete();
        $invoiceSync->sync($job);

        return $this->ok($this->dashboard->jobDetail($request->user(), $job->fresh(), []), "\"{$description}\" removed.");
    }
}
