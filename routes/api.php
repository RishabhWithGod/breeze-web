<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EstimateItemAttachmentController;
use App\Http\Controllers\Api\V1\EstimateItemController;
use App\Http\Controllers\Api\V1\EstimateItemNoteController;
use App\Http\Controllers\Api\V1\JobApprenticeAssignmentController;
use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\JobTaskAttachmentController;
use App\Http\Controllers\Api\V1\JobTaskController;
use App\Http\Controllers\Api\V1\JobTaskNoteController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\TimeEntryController;
use App\Http\Controllers\Api\V1\TimerController;
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

            Route::middleware('block.apprentice')->group(function () {
                Route::post('jobs/{job}/status', [JobController::class, 'changeStatus'])->name('jobs.status');
                Route::post('jobs/{job}/foremen/{foreman}/approve', [JobController::class, 'approveForeman'])->name('jobs.foremen.approve');
                // Putting an apprentice under a journeyman on this job — a
                // foreman's own call, from their Job Detail screen.
                Route::post('jobs/{job}/apprentices', [JobApprenticeAssignmentController::class, 'store'])->name('jobs.apprentices.store');

                Route::get('jobs/{job}/tasks', [JobTaskController::class, 'index'])->name('jobs.tasks.index');
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
                Route::post('time-entries/{entry}/submit', [TimeEntryController::class, 'submit'])->name('time-entries.submit');
            });

            Route::get('jobs/{job}/attendance', [AttendanceController::class, 'index'])->name('jobs.attendance.index');
            Route::post('jobs/{job}/attendance/check-in', [AttendanceController::class, 'checkIn'])->name('jobs.attendance.check-in');
            Route::post('jobs/{job}/attendance/check-out', [AttendanceController::class, 'checkOut'])->name('jobs.attendance.check-out');
            Route::get('attendance/today', [AttendanceController::class, 'today'])->name('attendance.today');

            Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
            Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        });
    });
});
