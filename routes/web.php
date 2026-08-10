<?php

use App\Http\Controllers\AiReviewController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DrawingDetailsController;
use App\Http\Controllers\EstimateController;
use App\Http\Controllers\EstimateDetailController;
use App\Http\Controllers\FinalTakeoffController;
use App\Http\Controllers\JobAssignmentController;
use App\Http\Controllers\JobAttachmentController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\JobEstimateController;
use App\Http\Controllers\JobNoteController;
use App\Http\Controllers\JobScheduleController;
use App\Http\Controllers\JobTaskController;
use App\Http\Controllers\JobTeamController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\ProcessingController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectDocumentController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\SchedulingController;
use App\Http\Controllers\StatePageController;
use App\Http\Controllers\SymbolReviewController;
use App\Http\Controllers\TakeoffHistoryController;
use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
});

/*
|--------------------------------------------------------------------------
| Authenticated routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // `/` and `/home` both open the dashboard.
    Route::get('/', [DashboardController::class, 'index']);
    Route::get('home', [DashboardController::class, 'index'])->name('home');

    // The AI Takeoff module lands on its history; upload sits beneath it.
    Route::prefix('ai-takeoff')->group(function () {
        Route::get('/', [TakeoffHistoryController::class, 'index'])->name('takeoffs.index');
        Route::get('upload', [UploadController::class, 'create'])->name('uploads.create');
        Route::post('upload', [UploadController::class, 'store'])->name('uploads.store');
        // Polled by the upload screen; never blocks a render.
        Route::get('engine-status', [UploadController::class, 'engineStatus'])->name('ai.engine-status');
    });

    Route::delete('takeoffs/{project}', [TakeoffHistoryController::class, 'destroy'])->name('takeoffs.destroy');
    Route::post('takeoffs/{project}/restore', [TakeoffHistoryController::class, 'restore'])->name('takeoffs.restore');

    Route::get('processing/{project}', [ProcessingController::class, 'show'])->name('processing.show');
    // Polled by the processing screen; also nudges a stalled run forward.
    Route::get('processing/{project}/status', [ProcessingController::class, 'status'])->name('processing.status');
    Route::post('processing/{project}/retry', [ProcessingController::class, 'retry'])->name('processing.retry');
    Route::post('processing/{project}/cancel', [ProcessingController::class, 'cancel'])->name('processing.cancel');
    Route::post('processing/{project}/restart', [ProcessingController::class, 'restart'])->name('processing.restart');

    /*
    | AI Review — every detection the model returned, and the decisions that
    | determine what reaches the final JSON.
    */
    Route::get('reviews/{result}', [AiReviewController::class, 'show'])->name('reviews.show');
    Route::get('reviews/{result}/original.json', [AiReviewController::class, 'original'])->name('reviews.original');
    Route::get('reviews/{result}/crops/{review}', [AiReviewController::class, 'crop'])->name('reviews.crop');
    Route::get('reviews/{result}/pages/{page}', [AiReviewController::class, 'pagePreview'])->name('reviews.page');
    Route::post('reviews/{result}/finalise', [AiReviewController::class, 'finalise'])->name('reviews.finalise');
    Route::post('reviews/{result}/reopen', [AiReviewController::class, 'reopen'])->name('reviews.reopen');

    // Symbol-level decisions. `merge`, `bulk` and `approve-remaining` come before
    // the `{review}` routes so they are never treated as a review id.
    Route::post('reviews/{result}/merge', [SymbolReviewController::class, 'merge'])->name('reviews.merge');
    Route::post('reviews/{result}/bulk', [SymbolReviewController::class, 'bulk'])->name('reviews.bulk');
    Route::post('reviews/{result}/approve-remaining', [SymbolReviewController::class, 'approveRemaining'])
        ->name('reviews.approveRemaining');
    Route::post('reviews/{result}/symbols/{review}/approve', [SymbolReviewController::class, 'approve'])->name('reviews.approve');
    Route::post('reviews/{result}/symbols/{review}/reject', [SymbolReviewController::class, 'reject'])->name('reviews.reject');
    Route::post('reviews/{result}/symbols/{review}/reset', [SymbolReviewController::class, 'reset'])->name('reviews.reset');
    Route::post('reviews/{result}/symbols/{review}/count', [SymbolReviewController::class, 'count'])->name('reviews.count');
    Route::post('reviews/{result}/symbols/{review}/rename', [SymbolReviewController::class, 'rename'])->name('reviews.rename');
    Route::post('reviews/{result}/symbols/{review}/note', [SymbolReviewController::class, 'note'])->name('reviews.note');
    Route::post('reviews/{result}/symbols/{review}/split', [SymbolReviewController::class, 'split'])->name('reviews.split');

    /*
    | The signed-off takeoff: final symbol table, exports, and the handoff into a
    | job and an estimate.
    */
    /*
    | The drawing itself: the PDF plus every detail the engine read off it. Opened
    | from the takeoff history and from an estimate. Declared before the
    | result-keyed routes below because it is keyed by project.
    */
    Route::get('takeoffs/{project}/pdf', [DrawingDetailsController::class, 'show'])->name('drawings.show');
    Route::get('takeoffs/{project}/pdf/file', [DrawingDetailsController::class, 'file'])->name('drawings.file');

    Route::get('takeoffs/{result}/final', [FinalTakeoffController::class, 'show'])->name('finals.show');
    Route::get('takeoffs/{result}/final/export/{format}', [FinalTakeoffController::class, 'export'])->name('finals.export');
    Route::get('takeoffs/{result}/annotated.pdf', [FinalTakeoffController::class, 'annotated'])->name('finals.annotated');
    Route::post('takeoffs/{result}/job', [FinalTakeoffController::class, 'storeJob'])->name('finals.job');
    Route::post('takeoffs/{result}/estimate', [FinalTakeoffController::class, 'storeEstimate'])->name('finals.estimate');

    /*
    | Projects — the record a takeoff, an estimate and a job all hang off, with
    | the drawing PDFs defined against it. `create` is declared before `{project}`
    | so it is never read as an id.
    */
    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    Route::post('projects/{project}/documents', [ProjectDocumentController::class, 'store'])
        ->name('projects.documents.store');
    Route::get('projects/{project}/documents/{document}', [ProjectDocumentController::class, 'show'])
        ->name('projects.documents.show');
    Route::delete('projects/{project}/documents/{document}', [ProjectDocumentController::class, 'destroy'])
        ->name('projects.documents.destroy');

    Route::get('results', [ResultsController::class, 'latest'])->name('results.latest');
    Route::get('results/{project}', [ResultsController::class, 'show'])->name('results.show');

    Route::get('estimates', [EstimateController::class, 'index'])->name('estimates.index');
    Route::get('estimates/create', [EstimateController::class, 'create'])->name('estimates.create');
    Route::post('estimates', [EstimateController::class, 'store'])->name('estimates.store');
    Route::delete('estimates/{estimate}', [EstimateController::class, 'destroy'])->name('estimates.destroy');
    Route::post('estimates/{estimate}/restore', [EstimateController::class, 'restore'])->name('estimates.restore');

    // Estimate detail: header fields, editable line items and exports.
    Route::get('estimates/{estimate}', [EstimateDetailController::class, 'show'])->name('estimates.show');
    Route::get('estimates/{estimate}/edit', [EstimateDetailController::class, 'edit'])->name('estimates.edit');
    Route::put('estimates/{estimate}', [EstimateDetailController::class, 'update'])->name('estimates.update');
    Route::post('estimates/{estimate}/items', [EstimateDetailController::class, 'storeItem'])->name('estimates.items.store');
    Route::put('estimates/{estimate}/items/{item}', [EstimateDetailController::class, 'updateItem'])->name('estimates.items.update');
    Route::delete('estimates/{estimate}/items/{item}', [EstimateDetailController::class, 'destroyItem'])->name('estimates.items.destroy');
    Route::get('estimates/{estimate}/pdf', [EstimateDetailController::class, 'pdf'])->name('estimates.pdf');
    Route::get('estimates/{estimate}/export/csv', [EstimateDetailController::class, 'exportCsv'])->name('estimates.export.csv');

    /*
    | Job management. `create` and `bulk` are declared before `{job}` so they are
    | never swallowed by the wildcard.
    */
    Route::get('jobs', [JobController::class, 'index'])->name('jobs.index');
    Route::get('jobs/create', [JobController::class, 'create'])->name('jobs.create');
    Route::post('jobs', [JobController::class, 'store'])->name('jobs.store');
    Route::post('jobs/bulk', [JobController::class, 'bulk'])->name('jobs.bulk');

    Route::get('jobs/{job}', [JobController::class, 'show'])->name('jobs.show');
    Route::get('jobs/{job}/edit', [JobController::class, 'edit'])->name('jobs.edit');
    Route::put('jobs/{job}', [JobController::class, 'update'])->name('jobs.update');
    Route::delete('jobs/{job}', [JobController::class, 'destroy'])->name('jobs.destroy');
    Route::post('jobs/{job}/restore', [JobController::class, 'restore'])->name('jobs.restore');
    Route::post('jobs/{job}/archive', [JobController::class, 'archive'])->name('jobs.archive');
    Route::post('jobs/{job}/unarchive', [JobController::class, 'unarchive'])->name('jobs.unarchive');
    Route::post('jobs/{job}/duplicate', [JobController::class, 'duplicate'])->name('jobs.duplicate');
    Route::post('jobs/{job}/status', [JobController::class, 'changeStatus'])->name('jobs.status');

    /*
    | The job's schedule: the plan, its tasks, and the graph that orders them.
    | Declared before the `{job}` wildcard routes below only in spirit — these are
    | more specific paths, so ordering does not matter, but they are grouped here to
    | keep the module readable.
    */
    Route::get('jobs/{job}/schedule', [JobScheduleController::class, 'show'])->name('jobs.schedule.show');
    Route::put('jobs/{job}/schedule', [JobScheduleController::class, 'update'])->name('jobs.schedule.update');
    Route::post('jobs/{job}/schedule/tasks', [JobTaskController::class, 'store'])->name('jobs.tasks.store');
    Route::post('jobs/{job}/schedule/reorder', [JobTaskController::class, 'reorder'])->name('jobs.tasks.reorder');

    Route::put('schedule-tasks/{task}', [JobTaskController::class, 'update'])->name('tasks.update');
    Route::delete('schedule-tasks/{task}', [JobTaskController::class, 'destroy'])->name('tasks.destroy');
    Route::post('schedule-tasks/{task}/complete', [JobTaskController::class, 'complete'])->name('tasks.complete');
    Route::post('schedule-tasks/{task}/delay', [JobTaskController::class, 'delay'])->name('tasks.delay');
    Route::post('schedule-tasks/{task}/move', [JobTaskController::class, 'move'])->name('tasks.move');
    Route::post('schedule-tasks/{task}/assignments', [JobTaskController::class, 'assign'])->name('tasks.assign');
    Route::delete('schedule-tasks/{task}/assignments/{assignment}', [JobTaskController::class, 'unassign'])
        ->name('tasks.unassign');
    Route::post('schedule-tasks/{task}/dependencies', [JobTaskController::class, 'addDependency'])
        ->name('tasks.dependencies.store');
    Route::delete('schedule-tasks/{task}/dependencies/{dependency}', [JobTaskController::class, 'removeDependency'])
        ->name('tasks.dependencies.destroy');
    Route::post('schedule-tasks/{task}/comments', [JobTaskController::class, 'comment'])->name('tasks.comments.store');

    // Team members
    Route::post('jobs/{job}/team', [JobTeamController::class, 'store'])->name('jobs.team.store');
    Route::delete('jobs/{job}/team/{member}', [JobTeamController::class, 'destroy'])->name('jobs.team.destroy');

    // Notes
    Route::post('jobs/{job}/notes', [JobNoteController::class, 'store'])->name('jobs.notes.store');
    Route::delete('jobs/{job}/notes/{note}', [JobNoteController::class, 'destroy'])->name('jobs.notes.destroy');

    // Attachments
    Route::post('jobs/{job}/attachments', [JobAttachmentController::class, 'store'])->name('jobs.attachments.store');
    Route::get('jobs/{job}/attachments/{attachment}', [JobAttachmentController::class, 'download'])->name('jobs.attachments.download');
    Route::delete('jobs/{job}/attachments/{attachment}', [JobAttachmentController::class, 'destroy'])->name('jobs.attachments.destroy');

    // Role-based staffing. Releasing keeps the row, so the panel doubles as the
    // assignment history.
    Route::post('jobs/{job}/assignments', [JobAssignmentController::class, 'store'])->name('jobs.assignments.store');
    Route::delete('jobs/{job}/assignments/{assignment}', [JobAssignmentController::class, 'destroy'])
        ->name('jobs.assignments.destroy');

    // Estimates raised from a job, and the estimate → project conversion
    Route::post('jobs/{job}/estimates', [JobEstimateController::class, 'store'])->name('jobs.estimates.store');
    Route::post('jobs/{job}/estimates/{estimate}/convert', [JobEstimateController::class, 'convert'])->name('jobs.estimates.convert');

    /*
    | Scheduling — the crew calendar, and the queue of work still to be booked.
    | `unassigned` and `schedules` are declared before nothing else needs to win,
    | but the group sits above the module catch-all so `/scheduling` resolves here.
    */
    Route::prefix('scheduling')->group(function () {
        /*
         * The module lands on the queue of work still to be booked — that is the
         * question the screen answers first — and the calendar is one click away.
         */
        Route::get('/', [SchedulingController::class, 'unassigned'])->name('scheduling.index');
        Route::get('calendar', [SchedulingController::class, 'calendar'])->name('scheduling.calendar');
        Route::get('availability', [SchedulingController::class, 'availability'])->name('scheduling.availability');
        Route::post('schedules', [SchedulingController::class, 'store'])->name('scheduling.store');
        Route::put('schedules/{schedule}', [SchedulingController::class, 'update'])->name('scheduling.update');
        Route::delete('schedules/{schedule}', [SchedulingController::class, 'destroy'])->name('scheduling.destroy');
    });

    Route::get('empty', [StatePageController::class, 'empty'])->name('states.empty');
    Route::get('error', [StatePageController::class, 'error'])->name('states.error');

    /*
    | Sidebar modules that ship in the drawer but are not built yet. Declared
    | last so a real route always wins over this catch-all, and constrained to
    | the known slugs so unknown paths still 404.
    */
    Route::get('{module}', [ModuleController::class, 'show'])
        ->whereIn('module', array_keys(ModuleController::MODULES))
        ->name('modules.show');
});
