<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\EstimateDetailController;
use App\Http\Controllers\FinalTakeoffController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\TimeEntryController;
use App\Http\Controllers\TimeTrackingReportController;
use App\Models\Estimate;
use App\Models\Project;
use App\Services\Export\SymbolExporter;
use App\Support\Ownership;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads on the phone — each one is the web's own export (same rows, same filters, same file),
 * handed to the signed-in person through the API.
 */
class ExportController extends Controller
{
    /** Approved time for a date range — Time Tracking › Reports › Export. */
    public function timeReport(Request $request, string $format): StreamedResponse|BinaryFileResponse
    {
        return app(TimeTrackingReportController::class)->export($request, $format);
    }

    /** The Time Log, as filtered — every session in the download. */
    public function timeEntries(Request $request, string $format): StreamedResponse|BinaryFileResponse
    {
        // The phone names its filters `job_id` / `team_member_id`; the web's export reads `job` / `team_member`.
        $request->merge(array_filter([
            'job' => $request->input('job_id'),
            'team_member' => $request->input('team_member_id'),
        ], fn ($v) => $v !== null));

        return app(TimeEntryController::class)->export($request, $format);
    }

    public function invoices(Request $request): StreamedResponse
    {
        $request->merge(array_filter(['job' => $request->input('job_id')], fn ($v) => $v !== null));

        return app(InvoiceController::class)->export($request);
    }

    public function estimate(Estimate $estimate): StreamedResponse
    {
        return app(EstimateDetailController::class)->exportCsv($estimate);
    }

    /** The signed-off takeoff's final symbol table (json / csv / xlsx). */
    public function finalTakeoff(Request $request, Project $project, string $format): StreamedResponse|BinaryFileResponse
    {
        abort_unless(Ownership::owns($request->user(), $project->user_id), 403);

        $result = $project->latestAiResult;
        abort_unless($result !== null, 404, 'No analysis has been run for this project yet.');

        return app(FinalTakeoffController::class)->export($result, $format, app(SymbolExporter::class));
    }
}
