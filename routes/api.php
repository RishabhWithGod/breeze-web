<?php

use App\Http\Controllers\Api\V1\AddressLookupController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\ClientAddressController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\EstimateController;
use App\Http\Controllers\Api\V1\EstimateItemAttachmentController;
use App\Http\Controllers\Api\V1\EstimateItemController;
use App\Http\Controllers\Api\V1\EstimateItemNoteController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\InvoicePaymentController;
use App\Http\Controllers\Api\V1\JobApprenticeAssignmentController;
use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\JobTaskAttachmentController;
use App\Http\Controllers\Api\V1\JobTaskController;
use App\Http\Controllers\Api\V1\JobTaskNoteController;
use App\Http\Controllers\Api\V1\JobTaskSetupController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProcessingController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\SymbolReviewController;
use App\Http\Controllers\Api\V1\TakeoffController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\TechnicianController;
use App\Http\Controllers\Api\V1\TimeEntryController;
use App\Http\Controllers\Api\V1\TimerController;
use App\Http\Controllers\Api\V1\TimeTrackingController;
use App\Http\Controllers\Api\V1\TimeTrackingReportController;
use App\Http\Controllers\Api\V1\TimeTrackingSettingController;
use Illuminate\Support\Facades\Route;

/**
 * The electrician mobile app's production API contract — see
 * docs/mobile-api.md for the full endpoint-by-endpoint reference.
 *
 * Every route here calls the same domain services/models the web app's
 * `routes/web.php` controllers already use (`TimerService`,
 * `Job::changeStatus()`, `TimeEntryWriteService`, ...) — there is no
 * second, mobile-only implementation of any business rule, and no
 * mobile-only database table. A future incompatible change gets a new
 * `v2` prefix group rather than breaking this one.
 */
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->name('auth.login');
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:signup')
        ->name('auth.register');
    Route::post('auth/two-factor/verify', [AuthController::class, 'verifyTwoFactor'])
        ->middleware('throttle:two-factor')
        ->name('auth.two-factor.verify');
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:password-reset')
        ->name('auth.forgot-password');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:password-reset')
        ->name('auth.reset-password');

    // Generous per-user budget bounding abuse of an already-issued token —
    // see `AppServiceProvider::registerRateLimiters()`. Not meant to
    // interfere with normal mobile app usage.
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        // Reachable regardless of account status: a pending/rejected
        // technician still needs to see their own status and be able to
        // sign out. Everything else sits behind `account.active` below.
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::put('auth/password', [AuthController::class, 'updatePassword'])->name('auth.password.update');
        Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');

        Route::middleware('account.active')->group(function () {
            // `jobs.index`/`jobs.show` stay reachable for an apprentice —
            // `Api\V1\JobController` itself trims the response to basic
            // info for them. Everything below is either task/material data,
            // a status/approval action, or the schedule (which is task
            // data in another shape) — an apprentice reaches none of it.
            Route::get('jobs', [JobController::class, 'index'])->name('jobs.index');
            Route::get('jobs/{job}', [JobController::class, 'show'])->name('jobs.show');
            Route::put('jobs/{job}', [JobController::class, 'update'])->name('jobs.update');

            // Manager-owned data (`Estimate::scopeOwnedBy`) — self-limiting
            // for a field crew account with no estimates of their own, so
            // this needs no extra role gate beyond `account.active`.
            Route::get('estimates', [EstimateController::class, 'index'])->name('estimates.index');
            Route::get('estimates/{estimate}', [EstimateController::class, 'show'])->name('estimates.show');
            Route::put('estimates/{estimate}', [EstimateController::class, 'update'])->name('estimates.update');
            Route::post('estimates/{estimate}/items', [EstimateController::class, 'storeItem'])->name('estimates.items.store');
            Route::put('estimates/{estimate}/items/{item}', [EstimateController::class, 'updateItem'])->name('estimates.items.update');
            Route::delete('estimates/{estimate}/items/{item}', [EstimateController::class, 'destroyItem'])->name('estimates.items.destroy');

            // Same shape: `Client`/`Project` are single-owner (`user_id`),
            // no staffing/company concept at all — self-limiting the same
            // way estimates already are.
            Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
            Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
            Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');
            Route::put('clients/{client}', [ClientController::class, 'update'])->name('clients.update');
            Route::post('clients/{client}/addresses', [ClientAddressController::class, 'store'])->name('clients.addresses.store');
            Route::put('clients/{client}/addresses/{address}', [ClientAddressController::class, 'update'])->name('clients.addresses.update');
            Route::delete('clients/{client}/addresses/{address}', [ClientAddressController::class, 'destroy'])->name('clients.addresses.destroy');

            // Address lookup for the Site Location field — same
            // server-side-only Google Places proxy web's own field uses.
            Route::get('address-lookup', [AddressLookupController::class, 'suggest'])
                ->middleware('throttle:address-lookup')
                ->name('address-lookup.suggest');
            Route::get('address-lookup/place', [AddressLookupController::class, 'place'])
                ->middleware('throttle:address-lookup')
                ->name('address-lookup.place');
            Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
            Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
            Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
            Route::put('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');

            Route::get('takeoffs', [TakeoffController::class, 'index'])->name('takeoffs.index');
            Route::get('takeoffs/{project}', [TakeoffController::class, 'show'])->name('takeoffs.show');
            Route::get('takeoffs/{project}/pdf', [TakeoffController::class, 'pdf'])->name('takeoffs.pdf');
            Route::get('takeoffs/{project}/overlay', [TakeoffController::class, 'overlay'])->name('takeoffs.overlay');
            Route::get('takeoffs/{project}/pages/{page}', [TakeoffController::class, 'page'])
                ->whereNumber('page')
                ->name('takeoffs.pages.show');

            // Upload Drawing → the start of a run. Mobile's counterpart to
            // web's own single Upload/Processing screens; the client polls
            // `processing` below for progress instead of waiting here.
            Route::post('takeoffs/{project}/upload', [UploadController::class, 'store'])->name('takeoffs.upload');
            Route::get('takeoffs/{project}/processing', [ProcessingController::class, 'status'])->name('takeoffs.processing.status');
            Route::post('takeoffs/{project}/processing/retry', [ProcessingController::class, 'retry'])->name('takeoffs.processing.retry');
            Route::post('takeoffs/{project}/processing/restart', [ProcessingController::class, 'restart'])->name('takeoffs.processing.restart');
            Route::post('takeoffs/{project}/processing/cancel', [ProcessingController::class, 'cancel'])->name('takeoffs.processing.cancel');

            // AI Review's real actions from mobile — the same mutations as
            // web's own AI Review / drawing overlay screens, scoped under
            // the takeoff's own project so a review row can't be acted on
            // through a project the signed-in user doesn't own.
            Route::post('takeoffs/{project}/symbols/{review}/approve', [SymbolReviewController::class, 'approve'])->name('takeoffs.symbols.approve');
            Route::post('takeoffs/{project}/symbols/{review}/reject', [SymbolReviewController::class, 'reject'])->name('takeoffs.symbols.reject');
            Route::post('takeoffs/{project}/symbols/{review}/reset', [SymbolReviewController::class, 'reset'])->name('takeoffs.symbols.reset');
            Route::post('takeoffs/{project}/symbols/{review}/count', [SymbolReviewController::class, 'count'])->name('takeoffs.symbols.count');
            Route::post('takeoffs/{project}/symbols/{review}/rename', [SymbolReviewController::class, 'rename'])->name('takeoffs.symbols.rename');
            Route::post('takeoffs/{project}/symbols/{review}/note', [SymbolReviewController::class, 'note'])->name('takeoffs.symbols.note');
            Route::post('takeoffs/{project}/symbols/{review}/split', [SymbolReviewController::class, 'split'])->name('takeoffs.symbols.split');
            Route::post('takeoffs/{project}/symbols/{review}/occurrences/{key}/toggle', [SymbolReviewController::class, 'occurrence'])->name('takeoffs.symbols.occurrences.toggle');
            Route::post('takeoffs/{project}/symbols/{review}/occurrences/{key}/move', [SymbolReviewController::class, 'moveOccurrence'])->name('takeoffs.symbols.occurrences.move');
            Route::post('takeoffs/{project}/symbols/{review}/occurrences/{key}/duplicate', [SymbolReviewController::class, 'duplicateOccurrence'])->name('takeoffs.symbols.occurrences.duplicate');
            Route::delete('takeoffs/{project}/symbols/{review}/occurrences/{key}', [SymbolReviewController::class, 'deleteOccurrence'])->name('takeoffs.symbols.occurrences.destroy');
            Route::post('takeoffs/{project}/symbols/merge', [SymbolReviewController::class, 'merge'])->name('takeoffs.symbols.merge');
            Route::post('takeoffs/{project}/symbols/manual-add', [SymbolReviewController::class, 'manualAdd'])->name('takeoffs.symbols.manual-add');
            Route::post('takeoffs/{project}/symbols/bulk', [SymbolReviewController::class, 'bulk'])->name('takeoffs.symbols.bulk');
            Route::post('takeoffs/{project}/symbols/undo', [SymbolReviewController::class, 'undoLast'])->name('takeoffs.symbols.undo');
            Route::post('takeoffs/{project}/symbols/approve-remaining', [SymbolReviewController::class, 'approveRemaining'])->name('takeoffs.symbols.approve-remaining');

            // Signing off the review, and carrying it forward into a job.
            Route::post('takeoffs/{project}/finalise', [TakeoffController::class, 'finalise'])->name('takeoffs.finalise');
            Route::post('takeoffs/{project}/reopen', [TakeoffController::class, 'reopen'])->name('takeoffs.reopen');
            Route::post('takeoffs/{project}/job', [TakeoffController::class, 'storeJob'])->name('takeoffs.job.store');

            // Breaking a job into tasks, straight after it is raised — from
            // the takeoff flow or from any other job the manager owns.
            Route::get('jobs/{job}/task-setup', [JobTaskSetupController::class, 'options'])->name('jobs.task-setup.options');
            Route::post('jobs/{job}/task-setup', [JobTaskSetupController::class, 'store'])->name('jobs.task-setup.store');
            Route::get('tasks/{task}/edit', [JobTaskSetupController::class, 'editOptions'])->name('tasks.edit-options');
            Route::put('tasks/{task}', [JobTaskSetupController::class, 'update'])->name('tasks.update');

            // Roster + pending mobile signups. Reading the list/detail is
            // open to any signed-in, active account (same as web); adding,
            // editing or removing a member is manager-only, enforced inside
            // each action rather than here — same reasoning the
            // approve/reject routes below give.
            Route::get('team', [TeamController::class, 'index'])->name('team.index');
            Route::get('team/{member}', [TeamController::class, 'show'])->name('team.show');
            Route::post('team', [TeamController::class, 'store'])->name('team.store');
            Route::put('team/{member}', [TeamController::class, 'update'])->name('team.update');
            Route::delete('team/{member}', [TeamController::class, 'destroy'])->name('team.destroy');
            // The crew group itself (a name), distinct from a person on one.
            Route::post('teams', [TeamController::class, 'storeTeam'])->name('teams.store');

            // Same `InvoiceSummaryCalculator` the web Dashboard's Billing
            // Snapshot uses — there was no mobile billing surface at all
            // before this.
            Route::get('billing/summary', [BillingController::class, 'summary'])->name('billing.summary');

            // Invoices — manager-only in practice (`InvoicePolicy::view()`
            // requires owning the invoice), same domain services/models as
            // web's `InvoiceController`/`InvoiceDetailController`/
            // `InvoicePaymentController`. `create-options` must stay ahead
            // of `{invoice}` or it would route-bind as an invoice id.
            Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
            Route::get('invoices/create-options', [InvoiceController::class, 'createOptions'])->name('invoices.create-options');
            Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
            Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
            Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
            Route::post('invoices/{invoice}/restore', [InvoiceController::class, 'restore'])->name('invoices.restore');
            Route::post('invoices/{invoice}/items', [InvoiceController::class, 'storeItem'])->name('invoices.items.store');
            Route::put('invoices/{invoice}/items/{item}', [InvoiceController::class, 'updateItem'])->name('invoices.items.update');
            Route::delete('invoices/{invoice}/items/{item}', [InvoiceController::class, 'destroyItem'])->name('invoices.items.destroy');
            Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
            Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])->name('invoices.mark-paid');
            Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
            Route::post('invoices/{invoice}/pay', [InvoicePaymentController::class, 'checkout'])->name('invoices.pay');
            Route::get('invoices/{invoice}/pay/confirm', [InvoicePaymentController::class, 'confirm'])->name('invoices.pay.confirm');

            // Acting on a pending signup — manager-only, enforced inside the
            // controller (same gate as web's `TechnicianController`) rather
            // than here, since it depends on the *acting* user's role, not
            // the route.
            Route::post('technicians/{user}/approve', [TechnicianController::class, 'approve'])->name('technicians.approve');
            Route::post('technicians/{user}/reject', [TechnicianController::class, 'reject'])->name('technicians.reject');

            Route::middleware('block.apprentice')->group(function () {
                Route::post('jobs/{job}/status', [JobController::class, 'changeStatus'])->name('jobs.status');
                Route::post('jobs/{job}/foremen/{foreman}/approve', [JobController::class, 'approveForeman'])->name('jobs.foremen.approve');
                // Putting an apprentice under a journeyman on this job — a
                // foreman's own call, from their Job Detail screen.
                Route::post('jobs/{job}/apprentices', [JobApprenticeAssignmentController::class, 'store'])->name('jobs.apprentices.store');

                Route::get('jobs/{job}/tasks', [JobTaskController::class, 'index'])->name('jobs.tasks.index');
                // Cross-job "My Tasks" feed — every task on every job this
                // user can access, mirroring the per-job endpoint's own
                // behaviour (no assignee filter there either).
                Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
                Route::get('tasks/{task}', [JobTaskController::class, 'show'])->name('tasks.show');
                Route::post('tasks/{task}/complete', [JobTaskController::class, 'complete'])->name('tasks.complete');
                Route::patch('tasks/{task}/progress', [JobTaskController::class, 'updateProgress'])->name('tasks.progress');
                Route::patch('tasks/{task}/status', [JobTaskController::class, 'setStatus'])->name('tasks.status');
                Route::patch('estimate-items/{item}/completion', [EstimateItemController::class, 'setCompletion'])->name('estimate-items.completion');

                Route::get('tasks/{task}/notes', [JobTaskNoteController::class, 'index'])->name('tasks.notes.index');
                Route::post('tasks/{task}/notes', [JobTaskNoteController::class, 'store'])->name('tasks.notes.store');

                Route::get('tasks/{task}/attachments', [JobTaskAttachmentController::class, 'index'])->name('tasks.attachments.index');
                Route::post('tasks/{task}/attachments', [JobTaskAttachmentController::class, 'store'])->name('tasks.attachments.store');
                Route::get('tasks/attachments/{attachment}', [JobTaskAttachmentController::class, 'show'])->name('tasks.attachments.show');

                // One note/photo per material line — what the Materials screen
                // actually composes against now, in place of the task-wide
                // note/photo pair above.
                Route::get('estimate-items/{item}/notes', [EstimateItemNoteController::class, 'index'])->name('estimate-items.notes.index');
                Route::post('estimate-items/{item}/notes', [EstimateItemNoteController::class, 'store'])->name('estimate-items.notes.store');

                Route::get('estimate-items/{item}/attachments', [EstimateItemAttachmentController::class, 'index'])->name('estimate-items.attachments.index');
                Route::post('estimate-items/{item}/attachments', [EstimateItemAttachmentController::class, 'store'])->name('estimate-items.attachments.store');
                Route::get('estimate-items/attachments/{attachment}', [EstimateItemAttachmentController::class, 'show'])->name('estimate-items.attachments.show');

                Route::get('jobs/{job}/schedule', [ScheduleController::class, 'show'])->name('jobs.schedule.show');
                // Cross-job "My Schedule" feed — upcoming crew shifts across
                // every job this user can access.
                Route::get('schedule', [ScheduleController::class, 'index'])->name('schedule.index');

                // Time-on-the-clock, not attendance — an apprentice's only
                // allowed action is the check-in/out pair below, not a timer
                // session or a submitted time entry.
                Route::get('timer', [TimerController::class, 'show'])->name('timer.show');
                Route::post('timer/start', [TimerController::class, 'start'])->name('timer.start');
                Route::post('timer/pause', [TimerController::class, 'pause'])->name('timer.pause');
                Route::post('timer/resume', [TimerController::class, 'resume'])->name('timer.resume');
                Route::post('timer/stop', [TimerController::class, 'stop'])->name('timer.stop');
                Route::post('timer/discard', [TimerController::class, 'discard'])->name('timer.discard');

                Route::get('time-entries', [TimeEntryController::class, 'index'])->name('time-entries.index');
                Route::post('time-entries', [TimeEntryController::class, 'store'])->name('time-entries.store');
                Route::get('time-entries/{entry}', [TimeEntryController::class, 'show'])->name('time-entries.show');
                Route::put('time-entries/{entry}', [TimeEntryController::class, 'update'])->name('time-entries.update');
                Route::delete('time-entries/{entry}', [TimeEntryController::class, 'destroy'])->name('time-entries.destroy');
                Route::post('time-entries/{entry}/submit', [TimeEntryController::class, 'submit'])->name('time-entries.submit');
                // Approve/reject/reopen — authorized inside `TimeEntryPolicy`
                // (project manager/admin/owner, and only on jobs they own),
                // not by a route-level role gate.
                Route::post('time-entries/{entry}/approve', [TimeEntryController::class, 'approve'])->name('time-entries.approve');
                Route::post('time-entries/{entry}/reject', [TimeEntryController::class, 'reject'])->name('time-entries.reject');
                Route::post('time-entries/{entry}/reopen', [TimeEntryController::class, 'reopen'])->name('time-entries.reopen');

                Route::get('time-tracking/week', [TimeTrackingController::class, 'week'])->name('time-tracking.week');
                // The Time Log Viewer — one row per technician per day,
                // merging in GPS attendance alongside timer/manual entries
                // (`DailyTimesheetBuilder`), same as web's own list.
                Route::get('time-tracking/days', [TimeTrackingController::class, 'days'])->name('time-tracking.days');
                Route::get('time-tracking/days/{user}/{date}', [TimeTrackingController::class, 'day'])->name('time-tracking.day');
                // Manager-only (`viewReports`/`manageSettings`), enforced
                // inside each controller same as everywhere else in this file.
                Route::get('time-tracking/reports', [TimeTrackingReportController::class, 'index'])->name('time-tracking.reports');
                Route::get('time-tracking/settings', [TimeTrackingSettingController::class, 'show'])->name('time-tracking.settings.show');
                Route::put('time-tracking/settings', [TimeTrackingSettingController::class, 'update'])->name('time-tracking.settings.update');
            });

            Route::get('jobs/{job}/attendance', [AttendanceController::class, 'index'])->name('jobs.attendance.index');
            Route::post('jobs/{job}/attendance/check-in', [AttendanceController::class, 'checkIn'])->name('jobs.attendance.check-in');
            Route::post('jobs/{job}/attendance/check-out', [AttendanceController::class, 'checkOut'])->name('jobs.attendance.check-out');
            Route::get('attendance/today', [AttendanceController::class, 'today'])->name('attendance.today');
            // Read-only detail — the Time Log Viewer's day screen drills
            // into one GPS check-in/out record. Same crew-visibility rule
            // as everywhere else here (own record, or `viewCrew`).
            Route::get('attendance/{attendance}', [AttendanceController::class, 'show'])->name('attendance.show');
            Route::get('attendance/{attendance}/photo', [AttendanceController::class, 'photo'])->name('attendance.photo');

            Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
            Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        });
    });
});
