<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\JobTaskController;
use App\Http\Controllers\Api\V1\NotificationController;
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
    Route::post('auth/two-factor/verify', [AuthController::class, 'verifyTwoFactor'])
        ->middleware('throttle:two-factor')
        ->name('auth.two-factor.verify');

    // Generous per-user budget bounding abuse of an already-issued token —
    // see `AppServiceProvider::registerRateLimiters()`. Not meant to
    // interfere with normal mobile app usage.
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

        Route::get('jobs', [JobController::class, 'index'])->name('jobs.index');
        Route::get('jobs/{job}', [JobController::class, 'show'])->name('jobs.show');
        Route::post('jobs/{job}/status', [JobController::class, 'changeStatus'])->name('jobs.status');

        Route::get('jobs/{job}/tasks', [JobTaskController::class, 'index'])->name('jobs.tasks.index');
        Route::get('tasks/{task}', [JobTaskController::class, 'show'])->name('tasks.show');
        Route::post('tasks/{task}/complete', [JobTaskController::class, 'complete'])->name('tasks.complete');
        Route::patch('tasks/{task}/progress', [JobTaskController::class, 'updateProgress'])->name('tasks.progress');

        Route::get('jobs/{job}/schedule', [ScheduleController::class, 'show'])->name('jobs.schedule.show');

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

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    });
});
