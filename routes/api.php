<?php

use App\Http\Controllers\Api\V1\AddendumController;
use App\Http\Controllers\Api\V1\AddressLookupController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\ChangeOrderController;
use App\Http\Controllers\Api\V1\ClientAddressController;
use App\Http\Controllers\Api\V1\ClientContactController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\EstimateBuilderController;
use App\Http\Controllers\Api\V1\EstimateController;
use App\Http\Controllers\Api\V1\EstimateItemAttachmentController;
use App\Http\Controllers\Api\V1\EstimateItemController;
use App\Http\Controllers\Api\V1\EstimateItemNoteController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\InvoicePaymentController;
use App\Http\Controllers\Api\V1\JobApprenticeAssignmentController;
use App\Http\Controllers\Api\V1\JobChangeOrderController;
use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\JobDocumentController;
use App\Http\Controllers\Api\V1\JobMaterialController;
use App\Http\Controllers\Api\V1\JobMessageController;
use App\Http\Controllers\Api\V1\JobPhotoController;
use App\Http\Controllers\Api\V1\CommodityController;
use App\Http\Controllers\Api\V1\JobTaskAttachmentController;
use App\Http\Controllers\Api\V1\JobTaskController;
use App\Http\Controllers\Api\V1\JobTaskNoteController;
use App\Http\Controllers\Api\V1\JobTaskSetupController;
use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\LeaveManagementController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OfficeCommodityController;
use App\Http\Controllers\Api\V1\OfficeJobController;
use App\Http\Controllers\Api\V1\OfficePaymentSettingsController;
use App\Http\Controllers\Api\V1\ProcessingController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\SchedulingController;
use App\Http\Controllers\Api\V1\SecurityController;
use App\Http\Controllers\Api\V1\SymbolReviewController;
use App\Http\Controllers\Api\V1\TakeoffController;
use App\Http\Controllers\Api\V1\TakeoffFlowController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\TechnicianController;
use App\Http\Controllers\Api\V1\SyncConflictController;
use App\Http\Controllers\Api\V1\TimeEntryController;
use App\Http\Controllers\Api\V1\TimeLogController;
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
    Route::get('companies', [AuthController::class, 'companies'])
        ->middleware('throttle:signup')
        ->name('companies.index');
    // An emailed team invitation: public — the person has no account yet.
    Route::get('invitations/{token}', [\App\Http\Controllers\Api\V1\InvitationController::class, 'show'])->name('invitations.show');
    Route::post('invitations/{token}', [\App\Http\Controllers\Api\V1\InvitationController::class, 'accept'])
        ->middleware('throttle:10,1')->name('invitations.accept');
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:signup')
        ->name('auth.register');
    // Web's own sign-up, for the app: name, email, password — then company, terms and payment setup.
    Route::post('auth/signup', [AuthController::class, 'signup'])
        ->middleware('throttle:signup')
        ->name('auth.signup');
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

        // Realtime: a phone listens to the same private channels the web does (Reverb). Pusher's
        // handshake asks this for a signature before it will subscribe — the same channel rules
        // (`routes/channels.php`) decide, so a person only ever hears their own.
        Route::post('broadcasting/auth', fn (\Illuminate\Http\Request $request) => \Illuminate\Support\Facades\Broadcast::auth($request))
            ->name('broadcasting.auth');

        // First-run setup — company, terms, plan, then Team Setup and Get Started.
        Route::get('onboarding/status', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'status'])->name('onboarding.status');
        Route::get('onboarding/company', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'company'])->name('onboarding.company');
        Route::post('onboarding/company', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'storeCompany'])->name('onboarding.company.store');
        Route::get('onboarding/terms', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'terms'])->name('onboarding.terms');
        Route::post('onboarding/terms', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'storeTerms'])->name('onboarding.terms.store');
        Route::get('onboarding/payment', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'payment'])->name('onboarding.payment');
        Route::post('onboarding/payment', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'startPayment'])->name('onboarding.payment.start');
        Route::post('onboarding/payment/confirm', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'confirmPayment'])->name('onboarding.payment.confirm');
        Route::get('onboarding/team', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'teamSetup'])->name('onboarding.team');
        Route::post('onboarding/team/invitations', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'sendInvitations'])->name('onboarding.team.invitations.store');
        Route::post('onboarding/team/invitations/{invitation}/resend', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'resendInvitation'])->name('onboarding.team.invitations.resend');
        Route::delete('onboarding/team/invitations/{invitation}', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'cancelInvitation'])->name('onboarding.team.invitations.cancel');
        Route::get('onboarding/get-started', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'getStarted'])->name('onboarding.get-started');
        Route::post('onboarding/get-started/skip/{step}', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'skipStep'])->name('onboarding.get-started.skip');
        Route::post('onboarding/get-started/finish', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'finish'])->name('onboarding.get-started.finish');
        Route::get('auth/sessions', [AuthController::class, 'sessions'])->name('auth.sessions');
        Route::post('auth/sessions/revoke-others', [AuthController::class, 'revokeOtherSessions'])->name('auth.sessions.revoke-others');
        Route::put('auth/password', [AuthController::class, 'updatePassword'])->name('auth.password.update');
        // The app's own two-factor switch — separate from the web's.
        Route::post('auth/two-factor/send', [AuthController::class, 'sendTwoFactorEnableCode'])
            ->middleware('throttle:two-factor')->name('auth.two-factor.send');
        Route::post('auth/two-factor/confirm', [AuthController::class, 'confirmTwoFactorEnable'])
            ->middleware('throttle:two-factor')->name('auth.two-factor.confirm');
        Route::post('auth/two-factor/disable', [AuthController::class, 'disableTwoFactor'])
            ->middleware('throttle:two-factor')->name('auth.two-factor.disable');
        Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');

        // Security Settings — web's own `SecuritySettingsController`, for the app.
        Route::get('security', [SecurityController::class, 'show'])->name('security.show');
        Route::post('security/recovery-codes', [SecurityController::class, 'regenerateRecoveryCodes'])
            ->middleware('throttle:two-factor')->name('security.recovery-codes');
        Route::post('security/email/challenge', [SecurityController::class, 'sendEmailChallenge'])
            ->middleware('throttle:two-factor')->name('security.email.challenge');
        Route::post('security/email/confirm', [SecurityController::class, 'confirmEmail'])
            ->middleware('throttle:two-factor')->name('security.email.confirm');
        Route::put('security/notifications/{eventType}', [SecurityController::class, 'updateNotificationPreference'])
            ->name('security.notifications.update');

        Route::middleware(['account.active', 'permissions'])->group(function () {
            // The floating "Resume" button — read on every screen (mobile's
            // own `ShellController`), same as web's own `takeoffFlow` prop
            // shared on every Inertia response.
            Route::get('takeoff-flow', [TakeoffFlowController::class, 'show'])->name('takeoff-flow.show');
            Route::delete('takeoff-flow', [TakeoffFlowController::class, 'destroy'])->name('takeoff-flow.destroy');

            // The phone's push token — any active account, apprentices included.
            Route::post('device-tokens', [DeviceTokenController::class, 'store'])->name('device-tokens.store');
            Route::delete('device-tokens', [DeviceTokenController::class, 'destroy'])->name('device-tokens.destroy');

            // `jobs.index`/`jobs.show` stay reachable for an apprentice —
            // `Api\V1\JobController` itself trims the response to basic
            // info for them. Everything below is either task/material data,
            // a status/approval action, or the schedule (which is task
            // data in another shape) — an apprentice reaches none of it.
            Route::get('jobs', [JobController::class, 'index'])->name('jobs.index');
            Route::get('jobs/{job}', [JobController::class, 'show'])->name('jobs.show');
            Route::post('jobs', [JobController::class, 'store'])->name('jobs.store');
            Route::put('jobs/{job}', [JobController::class, 'update'])->name('jobs.update');

            // Manager-owned data (`Estimate::scopeOwnedBy`) — self-limiting
            // for a field crew account with no estimates of their own, so
            // this needs no extra role gate beyond `account.active`.
            // The Estimate Builder: quantities into priced labor and material lines.
            Route::get('estimate-builder', [EstimateBuilderController::class, 'index'])->name('estimate-builder.index');
            Route::post('estimate-builder', [EstimateBuilderController::class, 'store'])->name('estimate-builder.store');
            Route::get('estimate-builder/price-list', [EstimateBuilderController::class, 'priceList'])->name('estimate-builder.price-list');
            Route::get('estimate-builder/{estimate}', [EstimateBuilderController::class, 'show'])->whereNumber('estimate')->name('estimate-builder.show');
            Route::put('estimate-builder/{estimate}', [EstimateBuilderController::class, 'save'])->whereNumber('estimate')->name('estimate-builder.save');
            Route::post('estimate-builder/{estimate}/request-approval', [EstimateBuilderController::class, 'requestApproval'])->whereNumber('estimate')->name('estimate-builder.request-approval');
            Route::post('estimate-builder/{estimate}/import', [EstimateBuilderController::class, 'import'])->whereNumber('estimate')->name('estimate-builder.import');
            Route::get('estimates', [EstimateController::class, 'index'])->name('estimates.index');
            Route::get('estimates/create-options', [EstimateController::class, 'createOptions'])->name('estimates.create-options');
            Route::post('estimates', [EstimateController::class, 'store'])->name('estimates.store');
            Route::post('estimates/{estimate}/restore', [EstimateController::class, 'restore'])->whereNumber('estimate')->name('estimates.restore');
            Route::get('estimates/{estimate}', [EstimateController::class, 'show'])->name('estimates.show');
            Route::delete('estimates/{estimate}', [EstimateController::class, 'destroy'])->name('estimates.destroy');
            Route::put('estimates/{estimate}', [EstimateController::class, 'update'])->name('estimates.update');
            Route::get('estimates/{estimate}/edit-options', [EstimateController::class, 'editOptions'])->name('estimates.edit-options');
            Route::put('estimates/{estimate}/labor-rate', [EstimateController::class, 'updateLaborRate'])->name('estimates.labor-rate');
            Route::post('estimates/{estimate}/items', [EstimateController::class, 'storeItem'])->name('estimates.items.store');
            Route::put('estimates/{estimate}/items/{item}', [EstimateController::class, 'updateItem'])->name('estimates.items.update');
            Route::delete('estimates/{estimate}/items/{item}', [EstimateController::class, 'destroyItem'])->name('estimates.items.destroy');

            // Where a project's addenda are listed — read-only, an
            // addendum is raised through the upload/takeoff flow, never
            // here (see `Api\V1\UploadController::store`'s own
            // `addendum_for_estimate_id` field).
            Route::get('addenda', [AddendumController::class, 'index'])->name('addenda.index');

            // Same shape: `Client`/`Project` are single-owner (`user_id`),
            // no staffing/company concept at all — self-limiting the same
            // way estimates already are.
            Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
            // Before `clients/{client}` so `create-options` is not read as a client id.
            Route::get('clients/create-options', [ClientController::class, 'createOptions'])->name('clients.create-options');
            Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
            Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');
            Route::put('clients/{client}', [ClientController::class, 'update'])->name('clients.update');
            Route::delete('clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');
            Route::post('clients/{client}/contacts', [ClientContactController::class, 'store'])->name('clients.contacts.store');
            Route::put('clients/{client}/contacts/{contact}', [ClientContactController::class, 'update'])->name('clients.contacts.update');
            Route::delete('clients/{client}/contacts/{contact}', [ClientContactController::class, 'destroy'])->name('clients.contacts.destroy');
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
            // Before `projects/{project}` so `create-options` is not read as a project id.
            Route::get('projects/create-options', [ProjectController::class, 'createOptions'])->name('projects.create-options');
            Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
            Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
            Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
            Route::put('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');

            Route::get('takeoffs', [TakeoffController::class, 'index'])->name('takeoffs.index');
            // Registered ahead of `takeoffs/{project}` so `upload-options` is not read as a project id.
            Route::get('takeoffs/upload-options', [UploadController::class, 'options'])->name('takeoffs.upload-options');
            Route::get('takeoffs/{project}', [TakeoffController::class, 'show'])->name('takeoffs.show');
            Route::delete('takeoffs/{project}', [TakeoffController::class, 'destroy'])->name('takeoffs.destroy');
            Route::post('takeoffs/{project}/restore', [TakeoffController::class, 'restore'])->whereNumber('project')->name('takeoffs.restore');
            // AI Review: web's `AiReviewController::show()` for the app, a page at a time, and the crops.
            Route::get('takeoffs/{project}/review', [ReviewController::class, 'show'])->name('takeoffs.review');
            Route::get('takeoffs/{project}/symbols/{review}/crop', [ReviewController::class, 'crop'])->name('takeoffs.symbols.crop');
            Route::get('takeoffs/{project}/pdf', [TakeoffController::class, 'pdf'])->name('takeoffs.pdf');
            Route::get('takeoffs/{project}/annotated', [TakeoffController::class, 'annotated'])->name('takeoffs.annotated');
            Route::get('takeoffs/{project}/overlay', [TakeoffController::class, 'overlay'])->name('takeoffs.overlay');
            Route::get('takeoffs/{project}/pages/{page}', [TakeoffController::class, 'page'])
                ->whereNumber('page')
                ->name('takeoffs.pages.show');

            // Upload Drawing → the start of a run. Mobile's counterpart to
            // web's own single Upload/Processing screens; the client polls
            // `processing` below for progress instead of waiting here.
            Route::get('ai/engine-status', [UploadController::class, 'engineStatus'])->name('ai.engine-status');
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
            Route::get('takeoffs/{project}/job-form', [TakeoffController::class, 'jobForm'])->name('takeoffs.job.form');
            Route::post('takeoffs/{project}/job', [TakeoffController::class, 'storeJob'])->name('takeoffs.job.store');

            // A takeoff's own paperwork — Drawing Details' "Documents"
            // entry. List/upload/delete only; mobile has no cross-project
            // Documents module.
            Route::get('takeoffs/{project}/documents', [DocumentController::class, 'index'])->name('takeoffs.documents.index');
            Route::post('takeoffs/{project}/documents', [DocumentController::class, 'store'])->name('takeoffs.documents.store');
            Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
            Route::get('documents/{document}/file', [DocumentController::class, 'file'])->name('documents.file');
            Route::get('documents/{document}/history', [DocumentController::class, 'history'])->name('documents.history');
            Route::post('documents/{document}/versions', [DocumentController::class, 'storeVersion'])->name('documents.versions.store');
            Route::post('documents/{document}/favorite', [DocumentController::class, 'favorite'])->name('documents.favorite');
            Route::post('documents/{document}/archive', [DocumentController::class, 'archive'])->name('documents.archive');
            Route::post('documents/{document}/restore', [DocumentController::class, 'restore'])->name('documents.restore');
            Route::post('documents/{document}/share', [DocumentController::class, 'share'])->name('documents.share');

            // Breaking a job into tasks, straight after it is raised — from
            // the takeoff flow or from any other job the manager owns.
            Route::get('jobs/{job}/task-setup', [JobTaskSetupController::class, 'options'])->name('jobs.task-setup.options');
            Route::post('jobs/{job}/task-setup', [JobTaskSetupController::class, 'store'])->name('jobs.task-setup.store');
            Route::get('tasks/{task}/edit', [JobTaskSetupController::class, 'editOptions'])->name('tasks.edit-options');
            Route::put('tasks/{task}', [JobTaskSetupController::class, 'update'])->name('tasks.update');
            Route::delete('tasks/{task}', [JobTaskSetupController::class, 'destroy'])->name('tasks.destroy');

            // Roster + pending mobile signups. Reading the list/detail is
            // open to any signed-in, active account (same as web); adding,
            // editing or removing a member is manager-only, enforced inside
            // each action rather than here — same reasoning the
            // approve/reject routes below give.
            Route::get('dashboard', [\App\Http\Controllers\Api\V1\DashboardController::class, 'index'])->name('dashboard.index');
            Route::get('invoices/export', [\App\Http\Controllers\Api\V1\ExportController::class, 'invoices'])->name('invoices.export');
            Route::get('estimates/{estimate}/export/csv', [\App\Http\Controllers\Api\V1\ExportController::class, 'estimate'])->name('estimates.export');
            Route::get('takeoffs/{project}/final/export/{format}', [\App\Http\Controllers\Api\V1\ExportController::class, 'finalTakeoff'])->whereIn('format', ['json', 'csv', 'xlsx'])->name('takeoffs.final.export');
            Route::get('office/jobs/{job}/scope', [OfficeJobController::class, 'scope'])->whereNumber('job')->name('office.jobs.scope');

            // Analytics and Job Costing — the same data, rules and numbers as the web's (AnalyticsDashboard).
            Route::get('office/analytics', [\App\Http\Controllers\Api\V1\OfficeAnalyticsController::class, 'index'])->name('office.analytics.index');
            Route::put('office/analytics/settings', [\App\Http\Controllers\Api\V1\OfficeAnalyticsController::class, 'updateSettings'])->name('office.analytics.settings');
            Route::get('office/analytics/export/{format}', [\App\Http\Controllers\Api\V1\OfficeAnalyticsController::class, 'export'])->whereIn('format', ['csv', 'xlsx'])->name('office.analytics.export');
            Route::get('office/jobs/{job}/costing', [\App\Http\Controllers\Api\V1\OfficeAnalyticsController::class, 'show'])->whereNumber('job')->name('office.jobs.costing.show');
            Route::post('office/jobs/{job}/costing/entries', [\App\Http\Controllers\Api\V1\OfficeAnalyticsController::class, 'storeCostEntry'])->whereNumber('job')->name('office.jobs.costing.entries.store');
            Route::delete('office/jobs/{job}/costing/entries/{entry}', [\App\Http\Controllers\Api\V1\OfficeAnalyticsController::class, 'destroyCostEntry'])->whereNumber(['job', 'entry'])->name('office.jobs.costing.entries.destroy');
            Route::get('office/jobs/{job}/schedule', [\App\Http\Controllers\Api\V1\JobScheduleBoardController::class, 'show'])->name('office.jobs.schedule.show');
            Route::put('office/jobs/{job}/schedule', [\App\Http\Controllers\Api\V1\JobScheduleBoardController::class, 'update'])->name('office.jobs.schedule.update');
            Route::post('schedule-tasks/{task}/delay', [\App\Http\Controllers\Api\V1\JobScheduleBoardController::class, 'delay'])->name('schedule-tasks.delay');
            Route::post('schedule-tasks/{task}/move', [\App\Http\Controllers\Api\V1\JobScheduleBoardController::class, 'move'])->name('schedule-tasks.move');
            Route::post('schedule-tasks/{task}/assignments', [\App\Http\Controllers\Api\V1\JobScheduleBoardController::class, 'assign'])->name('schedule-tasks.assign');
            Route::delete('schedule-tasks/{task}/assignments/{assignment}', [\App\Http\Controllers\Api\V1\JobScheduleBoardController::class, 'unassign'])->name('schedule-tasks.unassign');
            Route::post('schedule-tasks/{task}/dependencies', [\App\Http\Controllers\Api\V1\JobScheduleBoardController::class, 'addDependency'])->name('schedule-tasks.dependencies.store');
            Route::delete('schedule-tasks/{task}/dependencies/{dependency}', [\App\Http\Controllers\Api\V1\JobScheduleBoardController::class, 'removeDependency'])->name('schedule-tasks.dependencies.destroy');
            Route::post('office/jobs/{job}/schedule/reorder', [\App\Http\Controllers\Api\V1\JobScheduleBoardController::class, 'reorder'])->name('office.jobs.schedule.reorder');
            Route::get('office/settings/company', [\App\Http\Controllers\Api\V1\OfficeCompanyController::class, 'show'])->name('office.settings.company.show');
            Route::post('office/settings/company', [\App\Http\Controllers\Api\V1\OfficeCompanyController::class, 'update'])->name('office.settings.company.update');
            Route::get('roles-permissions', [\App\Http\Controllers\Api\V1\RolePermissionController::class, 'show'])->name('roles.show');
            Route::put('roles-permissions', [\App\Http\Controllers\Api\V1\RolePermissionController::class, 'update'])->name('roles.update');
            Route::get('team', [TeamController::class, 'index'])->name('team.index');
            Route::get('team/{member}', [TeamController::class, 'show'])->name('team.show');
            Route::post('team', [TeamController::class, 'store'])->name('team.store');
            Route::put('team/{member}', [TeamController::class, 'update'])->name('team.update');
            Route::delete('team/{member}', [TeamController::class, 'destroy'])->name('team.destroy');
            Route::post('team/{member}/deactivate', [TeamController::class, 'deactivate'])->name('team.deactivate');
            Route::post('team/{member}/reactivate', [TeamController::class, 'reactivate'])->name('team.reactivate');
            // The crew group itself (a name), distinct from a person on one.
            Route::post('teams', [TeamController::class, 'storeTeam'])->name('teams.store');
            Route::put('teams/{team}', [TeamController::class, 'updateTeam'])->name('teams.update');
            Route::delete('teams/{team}', [TeamController::class, 'destroyTeam'])->name('teams.destroy');

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
            Route::put('invoices/{invoice}/journeyman-hours/{user}', [InvoiceController::class, 'updateJourneymanHours'])->name('invoices.journeyman-hours.update');
            Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
            Route::post('invoices/{invoice}/pay', [InvoicePaymentController::class, 'checkout'])->name('invoices.pay');
            Route::get('invoices/{invoice}/pay/confirm', [InvoicePaymentController::class, 'confirm'])->name('invoices.pay.confirm');

            // Acting on a pending signup — manager-only, enforced inside the
            // controller (same gate as web's `TechnicianController`) rather
            // than here, since it depends on the *acting* user's role, not
            // the route.
            Route::post('technicians/{user}/approve', [TechnicianController::class, 'approve'])->name('technicians.approve');
            Route::post('technicians/{user}/reject', [TechnicianController::class, 'reject'])->name('technicians.reject');

            // The Time Log — every check-in cycle and timer/manual entry, one row each. Open to
            // every crew role (an apprentice reads their own), so outside the apprentice block.
            Route::get('time-log', [TimeLogController::class, 'index'])->name('time-log.index');

            // "My Schedule" — where and when to work. Open to every crew role, an apprentice
            // included, so it sits outside the apprentice block below.
            Route::get('schedule', [ScheduleController::class, 'index'])->name('schedule.index');
            // A change that clashed with an office edit, sent up for a manager to decide.
            Route::post('sync-conflicts', [SyncConflictController::class, 'store'])->name('sync-conflicts.store');
            Route::post('schedule/{shift}/acknowledge', [ScheduleController::class, 'acknowledge'])->whereNumber('shift')->name('schedule.acknowledge');

            // An apprentice reads their journeyman's tasks here — read-only; every task write is below.
            Route::get('jobs/{job}/schedule', [ScheduleController::class, 'show'])->name('jobs.schedule.show');

            Route::middleware('block.apprentice')->group(function () {
                Route::post('jobs/{job}/status', [JobController::class, 'changeStatus'])->name('jobs.status');
                Route::post('jobs/{job}/foremen/{foreman}/approve', [JobController::class, 'approveForeman'])->name('jobs.foremen.approve');
                // Putting an apprentice under a journeyman on this job — a
                // foreman's own call, from their Job Detail screen.
                Route::post('jobs/{job}/apprentices', [JobApprenticeAssignmentController::class, 'store'])->name('jobs.apprentices.store');

                // The job workspace's own Messages, Additional Work and
                // Documents — all scoped to a job the caller can access.
                Route::get('jobs/{job}/messages', [JobMessageController::class, 'index'])->name('jobs.messages.index');
                Route::post('jobs/{job}/messages', [JobMessageController::class, 'store'])->name('jobs.messages.store');
                Route::get('change-orders', [JobChangeOrderController::class, 'hub'])->name('change-orders.hub');
                Route::post('change-orders/by-key/{key}/attachments', [JobChangeOrderController::class, 'attachByKey'])->name('change-orders.attach-by-key');
                Route::get('jobs/{job}/change-orders', [JobChangeOrderController::class, 'index'])->name('jobs.change-orders.index');
                Route::post('jobs/{job}/change-orders', [JobChangeOrderController::class, 'store'])->name('jobs.change-orders.store');
                Route::get('jobs/{job}/change-orders/{changeOrder}', [JobChangeOrderController::class, 'show'])->whereNumber('changeOrder')->name('jobs.change-orders.show');
                Route::post('jobs/{job}/change-orders/{changeOrder}/attachments', [JobChangeOrderController::class, 'attach'])->whereNumber('changeOrder')->name('jobs.change-orders.attach');
                Route::get('jobs/{job}/change-orders/{changeOrder}/attachments/{attachment}', [JobChangeOrderController::class, 'download'])->whereNumber(['changeOrder', 'attachment'])->name('jobs.change-orders.download');
                // Material and Work Changes: actual use against the plan, what the
                // crew added, the commodity list to pick from, and evidence photos.
                Route::get('jobs/{job}/materials', [JobMaterialController::class, 'index'])->name('jobs.materials.index');
                Route::post('jobs/{job}/materials', [JobMaterialController::class, 'submit'])->name('jobs.materials.submit');
                Route::delete('jobs/{job}/materials/{material}', [JobMaterialController::class, 'destroy'])->name('jobs.materials.destroy');
                Route::get('commodities', [CommodityController::class, 'index'])->name('commodities.index');
                Route::get('jobs/{job}/photos', [JobPhotoController::class, 'index'])->name('jobs.photos.index');
                Route::post('jobs/{job}/photos', [JobPhotoController::class, 'store'])->name('jobs.photos.store');
                Route::get('jobs/{job}/photos/{attachment}', [JobPhotoController::class, 'show'])->name('jobs.photos.show');
                Route::get('jobs/{job}/documents', [JobDocumentController::class, 'index'])->name('jobs.documents.index');
                Route::get('jobs/{job}/documents/{document}/download', [JobDocumentController::class, 'download'])->name('jobs.documents.download');

                // The office's Payment Settings ("Settings") — web's own `PaymentSettingsController`.
                Route::prefix('office/settings')->name('office.settings.')
                    ->controller(OfficePaymentSettingsController::class)->group(function () {
                        Route::get('/', 'show')->name('show');
                        Route::get('transactions', 'transactions')->name('transactions');
                        Route::put('billing', 'updateBilling')->name('billing');
                        Route::put('subscription', 'changePlan')->name('subscription');
                        Route::get('plan', 'plan')->name('plan');
                        Route::post('seats', 'buySeats')->name('seats');
                        Route::post('processors/{processor}/connect', 'connect')->name('processors.connect');
                        Route::post('processors/{processor}/test', 'test')->name('processors.test');
                        Route::delete('processors/{processor}', 'disconnect')->name('processors.disconnect');
                    });

                // The office's Commodity List — web's own `CommodityListController`.
                Route::prefix('office/commodities')->name('office.commodities.')
                    ->whereNumber('item')->controller(OfficeCommodityController::class)->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::post('/', 'store')->name('store');
                        Route::post('import', 'import')->name('import');
                        Route::put('{item}', 'update')->name('update');
                        Route::post('{item}/archive', 'archive')->name('archive');
                    });

                // The office's Job Detail — web's own `JobController::show()` and its panels.
                // Not under `{job}`: act on several jobs at once, or bring one back from the trash.
                Route::post('office/jobs/bulk', [OfficeJobController::class, 'bulk'])->name('office.jobs.bulk');
                Route::post('office/jobs/{job}/restore', [OfficeJobController::class, 'restore'])->whereNumber('job')->name('office.jobs.restore');
                Route::post('office/jobs/{job}/archive', [OfficeJobController::class, 'archive'])->name('office.jobs.archive');
                Route::post('office/jobs/{job}/unarchive', [OfficeJobController::class, 'unarchive'])->name('office.jobs.unarchive');

                Route::put('office/jobs/{job}/field-materials/{material}', [OfficeJobController::class, 'updateFieldMaterial'])->whereNumber(['job', 'material'])->name('office.jobs.field-materials.update');
                Route::post('office/jobs/{job}/field-materials/{material}/approve', [OfficeJobController::class, 'approveFieldMaterial'])->whereNumber(['job', 'material'])->name('office.jobs.field-materials.approve');
                Route::delete('office/jobs/{job}/field-materials/{material}', [OfficeJobController::class, 'destroyFieldMaterial'])->whereNumber(['job', 'material'])->name('office.jobs.field-materials.destroy');

                Route::prefix('office/jobs/{job}')->name('office.jobs.')
                    ->controller(OfficeJobController::class)->group(function () {
                        Route::get('/', 'show')->name('show');
                        Route::delete('/', 'destroy')->name('destroy');
                        Route::post('status', 'changeStatus')->name('status');
                        Route::post('notes', 'storeNote')->name('notes.store');
                        Route::delete('notes/{note}', 'destroyNote')->name('notes.destroy');
                        Route::post('attachments', 'storeAttachment')->name('attachments.store');
                        Route::get('attachments/{attachment}', 'downloadAttachment')->name('attachments.download');
                        Route::delete('attachments/{attachment}', 'destroyAttachment')->name('attachments.destroy');
                        Route::delete('apprentices/{assignment}', 'destroyApprentice')->name('apprentices.destroy');
                    });

                // The office's Change Orders screens — web's own `ChangeOrderController`,
                // apart from the field crew's per-job "Additional Work" above.
                Route::prefix('office/change-orders')->name('office.change-orders.')
                    ->whereNumber(['changeOrder', 'attachment'])->controller(ChangeOrderController::class)->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::get('options', 'options')->name('options');
                        Route::post('/', 'store')->name('store');
                        Route::get('{changeOrder}', 'show')->name('show');
                        Route::put('{changeOrder}', 'update')->name('update');
                        Route::delete('{changeOrder}', 'destroy')->name('destroy');
                        Route::post('{changeOrder}/submit', 'submit')->name('submit');
                        Route::post('{changeOrder}/withdraw', 'withdraw')->name('withdraw');
                        Route::post('{changeOrder}/approve', 'approve')->name('approve');
                        Route::post('{changeOrder}/reject', 'reject')->name('reject');
                        Route::post('{changeOrder}/attachments', 'attach')->name('attach');
                        Route::get('{changeOrder}/attachments/{attachment}', 'download')->name('download');
                        Route::delete('{changeOrder}/attachments/{attachment}', 'detach')->name('detach');
                    });

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


                // Unassigned Queue → "Book crew" — mobile's counterpart to
                // web's own Scheduling calendar's booking action.
                // The crew calendar — web's own Scheduling → Calendar board.
                Route::get('scheduling/calendar', [SchedulingController::class, 'calendar'])->name('scheduling.calendar');
                Route::get('scheduling/unassigned', [SchedulingController::class, 'unassigned'])->name('scheduling.unassigned');
                Route::post('scheduling/schedules', [SchedulingController::class, 'store'])->name('scheduling.schedules.store');

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
                Route::get('time-tracking/log', [TimeTrackingController::class, 'log'])->name('time-tracking.log');
                Route::get('time-tracking/entries/export/{format}', [\App\Http\Controllers\Api\V1\ExportController::class, 'timeEntries'])->whereIn('format', ['csv', 'xlsx'])->name('time-tracking.entries.export');
                Route::get('time-tracking/reports/export/{format}', [\App\Http\Controllers\Api\V1\ExportController::class, 'timeReport'])->whereIn('format', ['csv', 'xlsx'])->name('time-tracking.reports.export');
                Route::post('attendance/{attendance}/check-out', [TimeTrackingController::class, 'checkOut'])->name('attendance.check-out');
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
            Route::post('jobs/{job}/attendance/undo', [AttendanceController::class, 'undo'])->name('jobs.attendance.undo');
            Route::post('jobs/{job}/attendance/corrections', [AttendanceController::class, 'correct'])->name('jobs.attendance.correct');
            Route::get('attendance/today', [AttendanceController::class, 'today'])->name('attendance.today');
            // Read-only detail — the Time Log Viewer's day screen drills
            // into one GPS check-in/out record. Same crew-visibility rule
            // as everywhere else here (own record, or `viewCrew`).
            Route::get('attendance/{attendance}', [AttendanceController::class, 'show'])->name('attendance.show');
            Route::post('attendance/{attendance}/corrections/{correction}/resolve', [AttendanceController::class, 'resolveCorrection'])->name('attendance.corrections.resolve');
            Route::get('attendance/{attendance}/photo', [AttendanceController::class, 'photo'])->name('attendance.photo');

            // The person's own time off: ask, see the answer, take a request back. The
            // manager's side (approve, reject) is on the web.
            Route::get('leave', [LeaveController::class, 'index'])->name('leave.index');
            Route::post('leave', [LeaveController::class, 'store'])->name('leave.store');
            Route::post('leave/{leave}/cancel', [LeaveController::class, 'cancel'])->name('leave.cancel');

            // The manager's side: everyone's requests, and the answer to each.
            Route::get('leave-requests', [LeaveManagementController::class, 'index'])->name('leave-requests.index');
            Route::post('leave-requests/{leave}/approve', [LeaveManagementController::class, 'approve'])->whereNumber('leave')->name('leave-requests.approve');
            Route::post('leave-requests/{leave}/reject', [LeaveManagementController::class, 'reject'])->whereNumber('leave')->name('leave-requests.reject');
            Route::post('leave-requests/{leave}/revoke', [LeaveManagementController::class, 'revoke'])->whereNumber('leave')->name('leave-requests.revoke');

            Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
            Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        });
    });
});
