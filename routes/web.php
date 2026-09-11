<?php

use App\Http\Controllers\AddressLookupController;
use App\Http\Controllers\AiReviewController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\BreezeBucksController;
use App\Http\Controllers\ClientAddressController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentFolderController;
use App\Http\Controllers\DrawingDetailsController;
use App\Http\Controllers\EstimateController;
use App\Http\Controllers\EstimateDetailController;
use App\Http\Controllers\FinalTakeoffController;
use App\Http\Controllers\ForemanController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceDetailController;
use App\Http\Controllers\InvoicePaymentController;
use App\Http\Controllers\JobAssignmentController;
use App\Http\Controllers\JobAttachmentController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\JobCostingController;
use App\Http\Controllers\JobEstimateController;
use App\Http\Controllers\JobNoteController;
use App\Http\Controllers\JobScheduleController;
use App\Http\Controllers\JobTaskController;
use App\Http\Controllers\JobTaskSetupController;
use App\Http\Controllers\JobTeamController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentSettingsController;
use App\Http\Controllers\PriceBookController;
use App\Http\Controllers\ProcessingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectDocumentController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\SchedulingController;
use App\Http\Controllers\SecuritySettingsController;
use App\Http\Controllers\StatePageController;
use App\Http\Controllers\SymbolReviewController;
use App\Http\Controllers\TakeoffFlowController;
use App\Http\Controllers\TakeoffHistoryController;
use App\Http\Controllers\TaskListController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TechnicianController;
use App\Http\Controllers\ThreeDViewController;
use App\Http\Controllers\TimeEntryController;
use App\Http\Controllers\TimerController;
use App\Http\Controllers\TimeTrackingController;
use App\Http\Controllers\TimeTrackingReportController;
use App\Http\Controllers\TimeTrackingSettingController;
use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Health checks — unauthenticated, no session/CSRF concerns either way.
|--------------------------------------------------------------------------
*/

Route::get('health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('health/ready', [HealthController::class, 'ready'])->name('health.ready');

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('signup', [RegisteredUserController::class, 'create'])->name('signup');
    Route::post('signup', [RegisteredUserController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('password.store');

    // The login-time 2FA challenge — reached only via a pending marker set by
    // a real credential check in `LoginRequest::authenticate()`, not by a
    // full session yet, so it lives under `guest` rather than `auth`.
    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge.create');
    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:two-factor')
        ->name('two-factor.challenge.store');
    Route::post('two-factor-challenge/resend', [TwoFactorChallengeController::class, 'resend'])
        ->middleware('throttle:two-factor')
        ->name('two-factor.challenge.resend');
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
        Route::post('upload', [UploadController::class, 'store'])
            ->middleware('throttle:ai-processing')
            ->name('uploads.store');
        // Polled by the upload screen; never blocks a render.
        Route::get('engine-status', [UploadController::class, 'engineStatus'])->name('ai.engine-status');
    });

    Route::delete('takeoffs/{project}', [TakeoffHistoryController::class, 'destroy'])->name('takeoffs.destroy');
    Route::post('takeoffs/{project}/restore', [TakeoffHistoryController::class, 'restore'])->name('takeoffs.restore');

    Route::get('processing/{project}', [ProcessingController::class, 'show'])->name('processing.show');
    // Polled by the processing screen; also nudges a stalled run forward.
    Route::get('processing/{project}/status', [ProcessingController::class, 'status'])->name('processing.status');
    Route::post('processing/{project}/retry', [ProcessingController::class, 'retry'])
        ->middleware('throttle:ai-processing')
        ->name('processing.retry');
    Route::post('processing/{project}/cancel', [ProcessingController::class, 'cancel'])->name('processing.cancel');
    Route::post('processing/{project}/restart', [ProcessingController::class, 'restart'])
        ->middleware('throttle:ai-processing')
        ->name('processing.restart');

    /*
    | AI Review — every detection the model returned, and the decisions that
    | determine what reaches the final JSON.
    */
    Route::get('reviews/{result}', [AiReviewController::class, 'show'])->name('reviews.show');
    /*
     * The spatial view of the same drawing. Read-only: every edit it offers
     * posts to the review endpoints below, so there is one workflow and one
     * source of truth. See ThreeDViewController.
     */
    Route::get('reviews/{result}/3d', [ThreeDViewController::class, 'show'])->name('reviews.threeD');
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
    Route::post('reviews/{result}/symbols/{review}/occurrences/{key}', [SymbolReviewController::class, 'occurrence'])->name('reviews.occurrence');
    Route::post('reviews/{result}/symbols/{review}/occurrences/{key}/move', [SymbolReviewController::class, 'moveOccurrence'])->name('reviews.occurrenceMove');
    Route::post('reviews/{result}/symbols/{review}/occurrences/{key}/duplicate', [SymbolReviewController::class, 'duplicateOccurrence'])->name('reviews.occurrenceDuplicate');
    Route::delete('reviews/{result}/symbols/{review}/occurrences/{key}', [SymbolReviewController::class, 'deleteOccurrence'])->name('reviews.occurrenceDelete');
    Route::post('reviews/{result}/symbols/manual', [SymbolReviewController::class, 'manualAdd'])->name('reviews.manualAdd');
    Route::post('reviews/{result}/undo', [SymbolReviewController::class, 'undoLast'])->name('reviews.undo');

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
    // Feeds the Site / Location field's suggestions. JSON, not Inertia — it
    // answers a keystroke, not a navigation.
    /*
     * Address lookup, in two steps: names while typing, then the place behind
     * the one that was picked. Both go through us so the Google key never
     * reaches the browser.
     */
    Route::get('address-lookup', [AddressLookupController::class, 'suggest'])
        ->middleware('throttle:address-lookup')
        ->name('address.lookup');
    Route::get('address-lookup/place', [AddressLookupController::class, 'place'])
        ->middleware('throttle:address-lookup')
        ->name('address.place');

    /*
     * The client register. A client is who the work is for; the work itself is
     * their projects, which live under `projects` below.
     */
    Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
    Route::get('clients/create', [ClientController::class, 'create'])->name('clients.create');
    Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
    Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');
    Route::get('clients/{client}/edit', [ClientController::class, 'edit'])->name('clients.edit');
    Route::put('clients/{client}', [ClientController::class, 'update'])->name('clients.update');
    Route::delete('clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    // Recorded from whichever screen needed the site — usually Create Job.
    // The address book belongs to the client, and every project of theirs
    // picks from it.
    Route::post('clients/{client}/addresses', [ClientAddressController::class, 'store'])
        ->name('clients.addresses.store');
    Route::put('clients/{client}/addresses/{address}', [ClientAddressController::class, 'update'])
        ->name('clients.addresses.update');
    Route::delete('clients/{client}/addresses/{address}', [ClientAddressController::class, 'destroy'])
        ->name('clients.addresses.destroy');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    // Starts the first AI takeoff run against the project's drawing already on
    // record — no separate upload step.
    Route::post('projects/{project}/takeoff', [ProcessingController::class, 'start'])
        ->middleware('throttle:ai-processing')
        ->name('projects.takeoff.start');

    // No `store`: a drawing PDF only ever arrives through AI Takeoff now, so a
    // client's drawings can be opened and removed here but not added.
    Route::get('projects/{project}/documents/{document}', [ProjectDocumentController::class, 'show'])
        ->name('projects.documents.show');
    // Which drawing the next takeoff runs against.
    Route::post('projects/{project}/documents/{document}/select', [ProjectDocumentController::class, 'select'])
        ->name('projects.documents.select');
    Route::delete('projects/{project}/documents/{document}', [ProjectDocumentController::class, 'destroy'])
        ->name('projects.documents.destroy');

    Route::get('results', [ResultsController::class, 'latest'])->name('results.latest');
    Route::get('results/{project}', [ResultsController::class, 'show'])->name('results.show');

    /*
     * The company's own rates, imported from its estimating workbooks. Read
     * only: the numbers arrive through `pricebook:import`, and this is where
     * anyone can check one without opening the database.
     */
    Route::get('price-book', [PriceBookController::class, 'index'])->name('price-book.index');
    Route::get('price-book/{priceBookItem}', [PriceBookController::class, 'show'])->name('price-book.show');

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

    // `/billing` is a real, non-redirecting alias of the Invoices list — the
    // same pattern `/time-tracking` uses for its own entries list — so the
    // sidebar's old placeholder link never 404s.
    Route::get('billing', [InvoiceController::class, 'index'])->name('billing.index');
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
    Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
    Route::post('invoices/{invoice}/restore', [InvoiceController::class, 'restore'])->name('invoices.restore');

    // Invoice detail: header fields, editable line items, the send/paid workflow and exports.
    Route::get('invoices/{invoice}', [InvoiceDetailController::class, 'show'])->name('invoices.show');
    Route::get('invoices/{invoice}/edit', [InvoiceDetailController::class, 'edit'])->name('invoices.edit');
    Route::put('invoices/{invoice}', [InvoiceDetailController::class, 'update'])->name('invoices.update');
    Route::post('invoices/{invoice}/items', [InvoiceDetailController::class, 'storeItem'])->name('invoices.items.store');
    Route::put('invoices/{invoice}/items/{item}', [InvoiceDetailController::class, 'updateItem'])->name('invoices.items.update');
    Route::delete('invoices/{invoice}/items/{item}', [InvoiceDetailController::class, 'destroyItem'])->name('invoices.items.destroy');
    Route::post('invoices/{invoice}/send', [InvoiceDetailController::class, 'send'])->name('invoices.send');
    Route::post('invoices/{invoice}/mark-paid', [InvoiceDetailController::class, 'markPaid'])->name('invoices.mark-paid');
    Route::get('invoices/{invoice}/pdf', [InvoiceDetailController::class, 'pdf'])->name('invoices.pdf');
    Route::post('invoices/{invoice}/pay', [InvoicePaymentController::class, 'checkout'])->name('invoices.pay');
    Route::get('invoices/{invoice}/pay/confirm', [InvoicePaymentController::class, 'confirm'])->name('invoices.pay.confirm');

    /*
    | Job management. `create` and `bulk` are declared before `{job}` so they are
    | never swallowed by the wildcard.
    */
    /*
     * Tasks and foremen across every job — the questions you have before you
     * know which job you are looking for. Both sit under Jobs in the rail.
     */
    // Dismissing the resume button: the takeoff stays, the reminder goes.
    Route::delete('takeoff-flow', [TakeoffFlowController::class, 'destroy'])->name('takeoff-flow.forget');

    Route::get('tasks', [TaskListController::class, 'index'])->name('tasks.index');
    Route::get('tasks/create', [TaskListController::class, 'create'])->name('tasks.create');
    // Editing a task is the setup screen again, aimed at one row — same fields,
    // same line picker, so the plan and the estimate stay in step.
    Route::get('tasks/{task}/edit', [JobTaskSetupController::class, 'edit'])->name('tasks.edit');
    Route::put('tasks/{task}', [JobTaskSetupController::class, 'update'])->name('tasks.edit.update');
    Route::delete('tasks/{task}', [JobTaskSetupController::class, 'destroy'])->name('tasks.remove');

    /*
     * The crew register, read by team. `/foremen` still holds one person's own
     * screens: the table and the model are still `foremen`, because a foreman
     * is what most of them are and renaming the column every task points at
     * would rewrite who ran what for nothing.
     */
    Route::get('teams', [TeamController::class, 'index'])->name('teams.index');
    Route::get('teams/create', [TeamController::class, 'create'])->name('teams.create');
    Route::post('teams', [TeamController::class, 'store'])->name('teams.store');

    // The old address, kept so a bookmark or an old link still lands somewhere.
    Route::redirect('foremen', '/teams')->name('foremen.index');
    Route::get('foremen/create', [ForemanController::class, 'create'])->name('foremen.create');
    // After `create`, so the literal segment is not read as a foreman's id.
    Route::get('foremen/{foreman}', [ForemanController::class, 'show'])->name('foremen.show');
    Route::get('foremen/{foreman}/edit', [ForemanController::class, 'edit'])->name('foremen.edit');
    Route::put('foremen/{foreman}', [ForemanController::class, 'update'])->name('foremen.update');
    Route::delete('foremen/{foreman}', [ForemanController::class, 'destroy'])->name('foremen.destroy');
    Route::post('foremen', [ForemanController::class, 'store'])->name('foremen.store');

    /*
     * Actions on technicians who signed up from the mobile app. The list
     * itself renders on Teams (`TeamController::index()`) alongside the
     * `foremen` register — this old address just lands there now.
     */
    Route::redirect('technicians', '/teams')->name('technicians.index');
    Route::post('technicians/{user}/approve', [TechnicianController::class, 'approve'])->name('technicians.approve');
    Route::post('technicians/{user}/reject', [TechnicianController::class, 'reject'])->name('technicians.reject');
    Route::put('technicians/{user}/team', [TechnicianController::class, 'assignTeam'])->name('technicians.team');

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
    // The step straight after Create Job: laying the work out in one go.
    Route::get('jobs/{job}/tasks/setup', [JobTaskSetupController::class, 'create'])->name('jobs.tasks.setup');
    Route::post('jobs/{job}/tasks/setup', [JobTaskSetupController::class, 'store'])->name('jobs.tasks.setup.store');

    Route::post('jobs/{job}/schedule/tasks', [JobTaskController::class, 'store'])->name('jobs.tasks.store');
    Route::post('jobs/{job}/schedule/reorder', [JobTaskController::class, 'reorder'])->name('jobs.tasks.reorder');

    // Job Costing: the dashboard across all jobs, and one job's own detail screen.
    Route::get('job-costing', [JobCostingController::class, 'index'])->name('job-costing.index');
    Route::get('job-costing/export/{format}', [JobCostingController::class, 'export'])->name('job-costing.export');
    Route::get('jobs/{job}/costing', [JobCostingController::class, 'show'])->name('job-costing.show');
    Route::post('jobs/{job}/costing/entries', [JobCostingController::class, 'storeCostEntry'])->name('job-costing.entries.store');
    Route::delete('jobs/{job}/costing/entries/{entry}', [JobCostingController::class, 'destroyCostEntry'])->name('job-costing.entries.destroy');

    // Documents: drawings, specs and other files, filed against jobs/estimates/folders.
    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('documents/create', [DocumentController::class, 'create'])->name('documents.create');
    Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('documents/{document}/preview', [DocumentController::class, 'preview'])->name('documents.preview');
    Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::get('documents/{document}/history', [DocumentController::class, 'history'])->name('documents.history');
    Route::post('documents/{document}/versions', [DocumentController::class, 'storeVersion'])->name('documents.versions.store');
    Route::post('documents/{document}/favorite', [DocumentController::class, 'favorite'])->name('documents.favorite');
    Route::post('documents/{document}/archive', [DocumentController::class, 'archive'])->name('documents.archive');
    Route::post('documents/{document}/restore', [DocumentController::class, 'restore'])->name('documents.restore');
    Route::post('documents/{document}/share', [DocumentController::class, 'share'])->name('documents.share');
    Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

    Route::post('document-folders', [DocumentFolderController::class, 'store'])->name('document-folders.store');

    // Notification Center: reads/writes the same `app_notifications` table the header bell does.
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

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
    Route::get('schedule-tasks/{task}/attachments/{attachment}', [JobTaskController::class, 'attachment'])
        ->name('tasks.attachments.show');

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

    /*
    | Time Tracking — the entries list (the module's landing screen, active
    | timer included), the week view, reports and settings. Sits above the
    | module catch-all so `/time-tracking` resolves here, the same way
    | Scheduling does.
    |
    | The bare `/time-tracking` path renders the same entries list as
    | `/time-tracking/entries` — kept as a real, non-redirecting alias (the
    | same pattern `ROUTES.history`/`ROUTES.aiTakeoff` use on the frontend) so
    | an old bookmark or link never 404s.
    */
    Route::prefix('time-tracking')->group(function () {
        Route::get('/', [TimeEntryController::class, 'index'])->name('time-tracking.index');
        Route::get('week', [TimeTrackingController::class, 'week'])->name('time-tracking.week');
        Route::get('reports', [TimeTrackingReportController::class, 'index'])->name('time-tracking.reports');
        Route::get('reports/export/{format}', [TimeTrackingReportController::class, 'export'])->name('time-tracking.reports.export');
        Route::get('settings', [TimeTrackingSettingController::class, 'edit'])->name('time-tracking.settings.edit');
        Route::put('settings', [TimeTrackingSettingController::class, 'update'])->name('time-tracking.settings.update');

        Route::get('entries', [TimeEntryController::class, 'index'])->name('time-entries.index');
        // Must precede the `entries/{entry}` show route below — both are one
        // literal segment, and Laravel matches route declarations in order.
        Route::get('entries/create', [TimeEntryController::class, 'create'])->name('time-entries.create');
        Route::get('entries/export/{format}', [TimeEntryController::class, 'export'])->name('time-entries.export');
        Route::post('entries', [TimeEntryController::class, 'store'])->name('time-entries.store');
        Route::get('entries/{entry}', [TimeEntryController::class, 'show'])->name('time-entries.show');
        Route::get('entries/{entry}/edit', [TimeEntryController::class, 'edit'])->name('time-entries.edit');
        Route::put('entries/{entry}', [TimeEntryController::class, 'update'])->name('time-entries.update');
        Route::delete('entries/{entry}', [TimeEntryController::class, 'destroy'])->name('time-entries.destroy');
        Route::post('entries/{entry}/submit', [TimeEntryController::class, 'submit'])->name('time-entries.submit');
        Route::post('entries/{entry}/approve', [TimeEntryController::class, 'approve'])->name('time-entries.approve');
        Route::post('entries/{entry}/reject', [TimeEntryController::class, 'reject'])->name('time-entries.reject');
        Route::post('entries/{entry}/reopen', [TimeEntryController::class, 'reopen'])->name('time-entries.reopen');
        Route::get('jobs/{job}/tasks', [TimeEntryController::class, 'jobTasks'])->name('time-entries.job-tasks');

        Route::post('timer/start', [TimerController::class, 'start'])->name('timer.start');
        Route::post('timer/pause', [TimerController::class, 'pause'])->name('timer.pause');
        Route::post('timer/resume', [TimerController::class, 'resume'])->name('timer.resume');
        Route::post('timer/stop', [TimerController::class, 'stop'])->name('timer.stop');
        Route::post('timer/discard', [TimerController::class, 'discard'])->name('timer.discard');
    });

    Route::get('empty', [StatePageController::class, 'empty'])->name('states.empty');
    Route::get('error', [StatePageController::class, 'error'])->name('states.error');

    // Payment Settings: processors, saved methods, billing defaults and the real transaction history.
    Route::get('settings', [PaymentSettingsController::class, 'index'])->name('settings.payment.index');
    Route::prefix('settings/payment')->name('settings.payment.')->group(function () {
        Route::post('processors/{processor}/connect', [PaymentSettingsController::class, 'connectProcessor'])->name('processors.connect');
        Route::post('processors/{processor}/test', [PaymentSettingsController::class, 'testProcessor'])->name('processors.test');
        Route::delete('processors/{processor}', [PaymentSettingsController::class, 'disconnectProcessor'])->name('processors.disconnect');
        Route::post('methods', [PaymentSettingsController::class, 'storePaymentMethod'])->name('methods.store');
        Route::patch('methods/{method}/default', [PaymentSettingsController::class, 'setDefaultPaymentMethod'])->name('methods.default');
        Route::delete('methods/{method}', [PaymentSettingsController::class, 'destroyPaymentMethod'])->name('methods.destroy');
        Route::put('billing', [PaymentSettingsController::class, 'updateBillingSettings'])->name('billing.update');
    });

    // Profile: the user's own name. Email/phone/password live on Security
    // since those go through an OTP-verified challenge.
    Route::get('profile', [ProfileController::class, 'index'])->name('profile.index');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');

    // Security Settings: 2FA enrollment, authentication method, email/phone
    // verification, per-event notification preferences, and the audit log.
    Route::get('security', [SecuritySettingsController::class, 'index'])->name('security.index');
    Route::prefix('security')->name('security.')->group(function () {
        Route::post('2fa/challenge', [SecuritySettingsController::class, 'sendTwoFactorChallenge'])->name('2fa.challenge');
        Route::post('2fa/confirm', [SecuritySettingsController::class, 'confirmTwoFactor'])->name('2fa.confirm');
        Route::post('2fa/disable', [SecuritySettingsController::class, 'disableTwoFactor'])->name('2fa.disable');
        Route::post('2fa/recovery-codes', [SecuritySettingsController::class, 'regenerateRecoveryCodes'])->name('2fa.recovery-codes');
        Route::put('method', [SecuritySettingsController::class, 'updateMethod'])->name('method.update');
        Route::post('email/challenge', [SecuritySettingsController::class, 'sendEmailChallenge'])->name('email.challenge');
        Route::post('email/confirm', [SecuritySettingsController::class, 'confirmEmail'])->name('email.confirm');
        Route::post('phone/challenge', [SecuritySettingsController::class, 'sendPhoneChallenge'])->name('phone.challenge');
        Route::post('phone/confirm', [SecuritySettingsController::class, 'confirmPhone'])->name('phone.confirm');
        Route::put('password', [SecuritySettingsController::class, 'updatePassword'])->name('password.update');
        Route::put('notifications/{eventType}', [SecuritySettingsController::class, 'updateNotificationPreference'])->name('notifications.update');
    });

    // Breeze Bucks: the real, ledger-derived balance, reward catalog and
    // redemption, plus manager/admin awards, adjustments and catalog upkeep.
    // Rewards Catalog, History and Award Bonus are each their own real
    // screen — not popups — reached from the landing page's action buttons.
    Route::get('breeze-bucks', [BreezeBucksController::class, 'index'])->name('breeze-bucks.index');
    Route::prefix('breeze-bucks')->name('breeze-bucks.')->group(function () {
        Route::get('rewards', [BreezeBucksController::class, 'rewards'])->name('rewards.index');
        Route::post('rewards/{reward}/redeem', [BreezeBucksController::class, 'redeem'])->name('rewards.redeem');
        Route::post('rewards', [BreezeBucksController::class, 'storeReward'])->name('rewards.store');
        Route::put('rewards/{reward}', [BreezeBucksController::class, 'updateReward'])->name('rewards.update');
        Route::delete('rewards/{reward}', [BreezeBucksController::class, 'destroyReward'])->name('rewards.destroy');
        Route::get('history', [BreezeBucksController::class, 'history'])->name('history.index');
        Route::get('award', [BreezeBucksController::class, 'awardForm'])->name('award.form');
        Route::post('award', [BreezeBucksController::class, 'award'])->name('award');
        Route::post('adjust', [BreezeBucksController::class, 'adjust'])->name('adjust');
    });
});
