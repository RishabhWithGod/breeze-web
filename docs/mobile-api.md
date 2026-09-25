# Mobile API — v1

The electrician mobile app's production API contract. Every endpoint here
calls the exact same domain services and models the web app's
`routes/web.php` controllers already call — `TimerService`,
`Job::changeStatus()`, `TimeEntryWriteService`, `JobTaskWorkflowService` —
so a mobile-created timer session, time entry, or status change is
indistinguishable in the database from one the web app created, and
reaches Phase 8's realtime broadcasts identically.

```
Electrician Mobile App
        │  Bearer <token>
        ▼
Laravel API (routes/api.php, prefix /api/v1)
        │  the SAME service/model calls routes/web.php makes
        ▼
MySQL  (work_jobs, job_tasks, timer_sessions, time_entries, app_notifications, ...)
        │  ShouldDispatchAfterCommit
        ▼
Laravel Event  (TimerStateChanged, JobStatusChanged, TimeEntryLogged, ...)
        │
        ▼
Laravel Reverb  (private-user.{id} / private-job.{id} / private-project.{id})
        │
        ▼
Web Portal  (Echo, usePrivateChannel — no manual refresh)
```

There is no mobile-only table anywhere in this contract. If a future
mobile client also needs realtime pushes of its own, see
[Realtime is not implemented on the mobile side](#realtime-is-not-implemented-on-the-mobile-side)
below — nothing here blocks adding it, but nothing here does it yet.

## Authentication

Token-based (Laravel Sanctum), not the web app's session cookie —
appropriate for a native client that isn't a browser.

`POST /api/v1/auth/login` reuses `App\Http\Requests\LoginRequest::authenticate()`
**verbatim** — the same 5-attempt rate limiting (keyed by
`email|ip`), the same password check, the same `SecurityEvent::LOGIN_FAILED`
audit row on a bad attempt, and critically, the same two-factor detection.
A mobile-specific reimplementation of login would have quietly bypassed
2FA for any account that has it enabled; reusing the FormRequest closes
that gap by construction.

| Method | URL | Auth | Notes |
| --- | --- | --- | --- |
| POST | `/api/v1/auth/login` | none | `{email, password}` → `{requiresTwoFactor: true, email}` if 2FA is on, else `{token, user, twoFactorEnabled}` |
| POST | `/api/v1/auth/two-factor/verify` | none | `{email, code}` → `{token, user, twoFactorEnabled}`. Code is a 6-digit OTP mailed via the same `OtpChallengeService`/`SecurityOtpCode` the web 2FA challenge uses, purpose `login_2fa`, 10-minute TTL |
| POST | `/api/v1/auth/logout` | Bearer | Revokes the current token (`currentAccessToken()->delete()`) |
| GET | `/api/v1/auth/me` | Bearer | `{id, name, email, role, initials}` |

A successful login also runs `DeviceRecognizer::recognize()` (the same
user-agent-hash device fingerprinting the web app uses) and logs
`SecurityEvent::LOGIN_SUCCESS` / `NEW_DEVICE_LOGIN` — the security audit
trail is one trail, not two.

**Token expiration**: `SANCTUM_TOKEN_EXPIRATION_MINUTES` (default 30 days,
see `config/sanctum.php`). A lost/stolen device's token stops working on
its own; `POST /auth/logout` revokes it immediately regardless.

**Never trust `user_id`/`job_id`/`electrician_id` from the client as an
identity claim.** Every endpoint below derives identity exclusively from
`$request->user()` (the Sanctum-authenticated token holder) — a `user_id`
field appearing in a request body (e.g. an assignment lookup) is treated
as a foreign key to validate against, never as "act as this person."

## Job access — stricter than the web app, deliberately

The web app has no per-job restriction: any signed-in user can open any
Job Detail page. The mobile surface is intentionally stricter —
`App\Services\Mobile\ElectricianJobAccess` is the single place that
decides which jobs a mobile session may see or act on:

- **Managers** (`project manager`, `site supervisor`, `foreman`, `admin`,
  `owner`, `estimator`) see every job on mobile too, matching their web
  access — they might carry the app as well as a browser.
- **Everyone else** (a field electrician) only sees jobs where they hold
  an active `job_assignments` row (`user_id` match, `released_at IS NULL`)
  **or** are attached to one of that job's tasks via
  `job_task_assignments`.

Every job/task/timer/time-entry endpoint below calls this same service —
the rule cannot drift between them.

## Jobs

| Method | URL | Auth | Notes |
| --- | --- | --- | --- |
| GET | `/api/v1/jobs` | Bearer | Paginated (`per_page`, max 50), scoped to `ElectricianJobAccess`. Never includes `budget` — omitted entirely, not conditionally redacted, since mobile field work has no need for contract budgets |
| GET | `/api/v1/jobs/{job}` | Bearer | 403 if inaccessible. Includes active `assignments` and `openTasksCount`; never a cost figure |
| POST | `/api/v1/jobs/{job}/status` | Bearer | `{status}` (one of `Job::STATUSES`) → calls `Job::changeStatus()` directly, which fires `JobStatusChanged` itself. **Realtime**: `job.status-changed` on `job.{id}` |

## Tasks

Scoped deliberately: mobile handles what a field electrician actually
does — see tasks, complete one, report progress on one in flight.
Planning actions (assign crew, reschedule, delete, dependencies, comments)
stay web-only; those are office/PM decisions Phase 9 didn't ask mobile to
carry, and adding them means extending `JobTaskWorkflowService`, not
writing parallel logic in the mobile controller.

| Method | URL | Auth | Notes |
| --- | --- | --- | --- |
| GET | `/api/v1/jobs/{job}/tasks` | Bearer | Paginated, requires job access |
| GET | `/api/v1/tasks/{task}` | Bearer | Requires access to the task's job |
| POST | `/api/v1/tasks/{task}/complete` | Bearer | `{actual_hours?, notes?}`. Authorized by `JobSchedulePolicy::completeTask()` — a planner, or whoever is actually assigned to the task. Delegates to `JobTaskWorkflowService::complete()`, the exact method `JobTaskController::complete()` (web) calls. **Realtime**: `schedule.changed` on `job.{id}` |
| PATCH | `/api/v1/tasks/{task}/progress` | Bearer | `{completion_pct (0-100), actual_hours?, notes?}` — a lighter update than `complete()`, no completion timestamp/notification. **Realtime**: `schedule.changed` |

## Timer — the critical piece

**No mobile-specific timer logic exists anywhere in this contract.**
Every action calls `App\Services\TimeTracking\TimerService` — the exact
service `TimerController` (web) calls. The service's own locked
"one active session per user" check (a `SELECT ... FOR UPDATE` inside a
transaction) is what prevents two concurrent starts, whether the second
attempt comes from a retried mobile request, a second device, or the web
app — this controller adds no timer-specific idempotency logic of its own
because none is needed; the guarantee already lives in the one place both
clients call into.

| Method | URL | Auth | Notes |
| --- | --- | --- | --- |
| GET | `/api/v1/timer` | Bearer | Current active session, or `null` |
| POST | `/api/v1/timer/start` | Bearer | `{job_id, job_task_id?, task_label?, description?, billable?}`. 403 if not staffed on the job; 422 (not 201) if a session is already running. **Realtime**: `timer.state-changed` on `user.{id}` |
| POST | `/api/v1/timer/pause` | Bearer | 422 if no active session for this user |
| POST | `/api/v1/timer/resume` | Bearer | |
| POST | `/api/v1/timer/stop` | Bearer | Converts the session into a draft `TimeEntry` (`source: timer`) and deletes the session — response includes `timeEntryId` |
| POST | `/api/v1/timer/discard` | Bearer | Deletes the session, logs nothing |

**Verified live** (see [Realtime verification](#realtime-verification-evidence)):
a timer started from a raw HTTP client (no browser) appeared in a
separate, already-open web session's `TimerIndicator` within ~2 seconds,
with no page refresh — through pause, resume, and stop.

## Time entries

| Method | URL | Auth | Notes |
| --- | --- | --- | --- |
| GET | `/api/v1/time-entries` | Bearer | Paginated, scoped to the caller's own entries only |
| POST | `/api/v1/time-entries` | Bearer | `{job_id, job_task_id?, task_label?, date, start_time?, end_time?, break_minutes?, hours?, description?, billable?}`. Either `hours` or a `start_time`+`end_time` pair is required. 403 if not staffed on the job. Delegates to `TimeEntryWriteService::save()` — the exact service the web "Add Time Entry" form and the timer's `stop()` flow both use. **Realtime**: `time-entry.logged` on `job.{id}` |
| GET | `/api/v1/time-entries/{entry}` | Bearer | `TimeEntryPolicy::view` — own entry, or a foreman/manager |
| POST | `/api/v1/time-entries/{entry}/submit` | Bearer | Owner only, only while `draft`/`rejected`. **Realtime**: `time-entry.submitted` |

`laborCost`/`billableAmount`/`billableRate`/`costRate` are always `null` in
every response to a non-manager — `TimeEntryResource` gates them behind
`can('viewJobCosts', TimeEntry::class)`, the same check the web app's
Job Costing screens use. Approve/reject are **not** mobile endpoints — a
manager approves from the web app, exactly as before; the mobile app
learns about it through `notification.created` (see below) and, if it's
viewing that job, `time-entry.approved`/`time-entry.rejected` on
`job.{id}`.

## Schedule (read-only)

`GET /api/v1/jobs/{job}/schedule` — the job's plan window/status plus
*this person's own tasks*, not the web Schedule screen's full
timeline/calendar/dependency-graph payload (sized for a desktop click-
through UI, not a mobile data budget). Returns
`{schedule: {...} | null, myTasks: [...]}` — `null` with a clear message
when no schedule has been built yet, never a fabricated one.

No mutation endpoint exists here: schedule planning (dates, working week,
staffing, dependencies) stays a web/office action. The one
schedule-affecting thing mobile does — completing a task — already goes
through `POST /tasks/{task}/complete` above, which broadcasts
`schedule.changed` itself.

## Invoices

Full parity with web's `InvoiceController`/`InvoiceDetailController`/
`InvoicePaymentController` — same `Invoice` model, same `InvoicePolicy`
(manager-only; `view` requires owning the invoice), same
`EstimateInvoiceSync`/`ClientDirectory`/`StripeConnector`. No job-cost/
journeyman-hours breakdown and no activity-feed/notification side-effects
on send/mark-paid — trimmed the same way this API already trims web-only
content elsewhere.

| Method | URL | Notes |
| --- | --- | --- |
| GET | `/api/v1/invoices` | Same filters as web (`search`, `status`, `client`, `job_id`, `date_from`, `date_to`, `amount_min`, `amount_max`, `sort`); paginated (`page`/`per_page`, capped 50), newest-first by default. Also returns `clients`/`jobs` (filter pickers), `summary` (`InvoiceSummaryCalculator`), `can` (`create`/`manage`) |
| GET | `/api/v1/invoices/create-options` | `nextNumber`, `clients`, `jobs` (unfiltered — same as web, eligibility is only enforced on submit), `estimates` (sent/approved, not yet invoiced) |
| POST | `/api/v1/invoices` | Creates a draft; 422 with a `job_id` field error if the job isn't completed or is already invoiced; copies an estimate's lines via `EstimateInvoiceSync` if `estimate_id` given |
| GET | `/api/v1/invoices/{invoice}` | Full detail: header fields, `items`, `can` (`update`/`delete`/`send`/`markPaid`), `stripeConnected` |
| PUT | `/api/v1/invoices/{invoice}` | Header only (client/job/estimate/dates/tax/notes) — status never changes here |
| DELETE | `/api/v1/invoices/{invoice}` | Soft delete; draft/sent only, never paid |
| POST | `/api/v1/invoices/{invoice}/restore` | Undo — plain int id (a trashed row never route-binds) |
| POST/PUT/DELETE | `/api/v1/invoices/{invoice}/items[/{item}]` | Line-item CRUD; every write recalculates `subtotal`/`taxTotal`/`total` server-side and returns the full updated invoice |
| POST | `/api/v1/invoices/{invoice}/send` | Draft → sent; 422 if there are no line items yet |
| POST | `/api/v1/invoices/{invoice}/mark-paid` | Sent → paid; records the entire balance, writes a manual `PaymentTransaction` |
| GET | `/api/v1/invoices/{invoice}/pdf` | Same `InvoicePdfWriter` as web, streamed as `application/pdf` |
| POST | `/api/v1/invoices/{invoice}/pay` | Starts a Stripe Checkout session for the outstanding balance; returns `{checkoutUrl, sessionId}` — the app opens `checkoutUrl` in an in-app browser tab. No Stripe SDK, no key, ever, on the client |
| GET | `/api/v1/invoices/{invoice}/pay/confirm?session_id=...` | Verifies the session directly against Stripe (never trusts a redirect alone) and, if paid, updates the invoice and records the transaction — same dedup-by-`external_reference` logic as web, subtleties included |

## Notifications

Reuses `app_notifications` and `NotificationResource` exactly as the web
Notification Center does — no separate mobile notification table, no
separate read/unread model.

| Method | URL | Auth | Notes |
| --- | --- | --- | --- |
| GET | `/api/v1/notifications` | Bearer | Paginated, plus `unreadCount` |
| POST | `/api/v1/notifications/{notification}/read` | Bearer | Ownership-checked (403 on someone else's); idempotent |
| POST | `/api/v1/notifications/read-all` | Bearer | Only the caller's own unread rows |

### Realtime is not implemented on the mobile side

`notification.created` (and every other Phase 8 event) broadcasts on a
private channel authorized via session cookie (`Broadcast::routes()`'s
default `web` middleware) — a Sanctum-token mobile client has no session
to authenticate a WebSocket subscription with. This phase does not wire
Echo into a mobile client (none exists in this repo to test against);
**mobile push notification delivery is not implemented and this
document makes no claim that it is** — a real mobile app would poll
`GET /notifications` or receive push via APNs/FCM, neither of which this
phase built. What Phase 8/9 guarantee is the reverse direction: an action
taken on mobile reaches an already-open **web** session in realtime.

## Response contract

Every endpoint returns the same envelope:

```json
{ "success": true, "message": "…", "data": { } }
```

```json
{ "success": false, "message": "…", "errors": { "field": ["…"] } }
```

| Status | Meaning |
| --- | --- |
| 200 | Success (read, or a state change with no new resource) |
| 201 | A new resource was created (timer session, time entry) |
| 401 | No token, or an invalid/expired/revoked one |
| 403 | Authenticated, but not authorized for this job/task/entry/notification |
| 404 | The referenced job/task/entry/notification does not exist |
| 422 | Validation failed, or a business rule was violated (e.g. "a timer is already running") |
| 500 | Unexpected server error — never a fabricated success |

`bootstrap/app.php`'s exception handler normalizes every `api/*` response
into this shape, including framework-level exceptions
(`ValidationException` → 422, `AuthenticationException` → 401,
`AuthorizationException`/`AccessDeniedHttpException` → 403,
`ModelNotFoundException`/`NotFoundHttpException` → 404) — a controller
never has to remember to wrap an error by hand.

## Idempotency and retry safety

Mobile networks retry. What's safe to blindly retry and what isn't:

| Endpoint | Retry-safe? | Why |
| --- | --- | --- |
| `GET *` (jobs, tasks, timer, entries, schedule, notifications) | Yes | Read-only |
| `POST /timer/start` | Yes, but the retry gets a 422 | `TimerService`'s locked existence check refuses a second session — a retried start after a real one succeeded fails loudly rather than creating a duplicate. The **first** attempt's session is unaffected |
| `POST /timer/pause` / `/resume` | Yes (idempotent in effect) | Both no-op if the session is already in the target state (`if ($session->isPaused()) return $session;` etc.) |
| `POST /timer/stop` | **No** — do not blindly retry | The first call deletes the session and creates a `TimeEntry`; a retry finds no active session and returns 422 ("no active timer"), which is the correct signal to *stop* retrying, not evidence of failure. A client should treat "422: no active timer" after a stop attempt as **probably already succeeded** and re-fetch `GET /timer` (null) and the entries list to confirm, rather than assuming failure |
| `POST /timer/discard` | Same as `/stop` | |
| `POST /time-entries` | **No** — each call creates a new row | There is no client-supplied idempotency key today. A client that must retry a create should first `GET /time-entries` and check for a very recent entry matching the same job/date/hours before resubmitting. Adding a proper `Idempotency-Key` header (cached against the resulting entry id) is the recommended follow-up if duplicate entries turn out to be a real problem in the field — not built here because there is no evidence yet that it's needed |
| `POST /time-entries/{id}/submit` | Yes (idempotent) | `changeStatus()` no-ops if already in the target state; a second submit of an already-submitted entry is a harmless no-op |
| `POST /jobs/{id}/status` | Yes (idempotent) | `Job::changeStatus()` returns immediately, broadcasting nothing, if `$from === $status` |
| `POST /tasks/{id}/complete` | **No** — do not blindly retry | Re-running it re-fires `JobTaskCompleted` (Breeze Bucks award, activity row) a second time. A client should check the task's `status` before retrying a completion whose response was lost |
| `PATCH /tasks/{id}/progress` | Yes (idempotent) | Setting the same `completion_pct` twice changes nothing observable beyond a duplicate activity row |
| `POST /notifications/{id}/read` | Yes (idempotent) | No-ops if already read |
| `POST /auth/login` | Yes | Rate-limited (5/attempt window), not resource-creating on failure |

## Pagination

Every list endpoint (`jobs`, `tasks`, `time-entries`, `notifications`)
paginates — `per_page` query param, capped server-side (50 for jobs/time-
entries/notifications, 100 for tasks) regardless of what the client
requests, matching the pattern the web app's own paginated screens use.
None of them return an unbounded result set.

## Performance

- `jobs`/`tasks` index endpoints eager-load `foreman`/`assignments.member`
  — no N+1 across a page of results.
- `TimerSessionResource`/`TimeEntryResource`/`JobTaskResource`/
  `NotificationResource` are the same, already-existing API Resources the
  web app uses elsewhere — no new ad-hoc serialization shape, and no
  raw Eloquent model ever leaves a controller.
- The schedule endpoint deliberately does **not** reuse
  `JobScheduleController::show()`'s full ~16-key payload (timeline,
  calendar, dependency graph, crew shifts) — that's sized for a desktop
  screen's click-through UI, not a mobile response budget. It's a
  purpose-built, much smaller `{schedule, myTasks}` shape instead.

## Realtime verification evidence

Verified live against a running `reverb:start` + `queue:work`, not just
PHPUnit:

1. **Timer, mobile → web**: a raw HTTP client (curl-equivalent, no
   browser) logged in as a real test electrician account, started a
   timer against a real job. A separate, already-open web browser session
   for the *same* user — sitting idle on the Dashboard — showed the
   `TimerIndicator` pill appear within ~2 seconds, with zero page
   interaction. Pause, resume, and stop were each reflected the same way;
   stop made the indicator disappear.
2. **Job status, mobile → web**: the same mobile client changed a job's
   status. A *different* user's web session (a Project Manager, viewing
   that Job Detail page, never reloaded) showed the status pill and the
   status `<select>`'s value change from `in-progress` to `delayed` —
   confirmed by reading the controlled `<select>`'s actual value, not
   fuzzy text matching.
3. **Time entry, mobile → web**: the mobile client created a time entry.
   Network-level inspection of the open web session confirmed a
   `X-Inertia-Partial-Data: job,timeTracking,jobCosting` request fired
   automatically in response — the exact partial reload `JobShow.tsx`'s
   `resync()` callback issues for a `time-entry.logged` event, and
   nothing else.
4. **Database integrity**: every row created during the run (`timer_
   sessions`, `time_entries`, `job_status_changes`, `job_task_
   assignments`, `security_events`) was checked directly against MySQL —
   correct `user_id`, `job_id`, `team_member_id` (auto-resolved via
   `TeamMemberResolver`, not fabricated), and status values; zero orphans.
5. **Actor attribution**: the `job_status_changes` row from the mobile-
   triggered status change correctly recorded the mobile user as
   `user_id`, via the plain `Auth::id()` call already inside
   `Job::changeStatus()` — no special-casing was needed for this to work,
   because Laravel's `auth:sanctum` middleware sets the resolved guard as
   the request's effective default.

All test data (the dedicated E2E electrician account and every row it
created) was deleted after evidence was captured; no seeded/demo/business
data was touched.

## Production configuration

- **`SANCTUM_TOKEN_EXPIRATION_MINUTES`** — set explicitly (default 30
  days). Never leave tokens non-expiring in production.
- **CORS** — not configured, deliberately. CORS is a browser-enforced
  restriction; a native mobile HTTP client (this contract's only
  consumer) never sends a `Origin` header a browser would, and never
  enforces the policy either way. If a browser-based client (a future
  admin SPA, a Postman-in-the-browser style tool) needs to call this API
  cross-origin, add `config/cors.php` then — don't pre-emptively open it
  for a consumer that doesn't need it.
- **`APP_URL`** — must be the real production domain; several resources
  (e.g. `reviewUrl` in the AI Takeoff payload) build absolute-ish links
  from it. A `localhost`/`127.0.0.1` value here would leak into a mobile
  client's JSON in production.
- **`APP_DEBUG=false`** — same rule as the rest of the app; a stack trace
  in a JSON error response is exactly as unsafe as one in an HTML page.
  This is enforced generically for `api/*` in `bootstrap/app.php`'s
  exception handler (a ≥500 status always returns a generic message,
  regardless of `APP_DEBUG`), but the underlying setting still governs
  whether Laravel logs the detail server-side — leave it `false`.
- **Queue worker + Reverb** — every write endpoint's broadcast is queued;
  without `queue:work` running (see
  [`deploy/supervisor/breeze.conf`](../deploy/supervisor/breeze.conf)),
  mobile writes still succeed (the database is unaffected) but the web
  app stops hearing about them in realtime, silently falling back to
  "correct on next page load" — worth an alert, not a hard failure.
- **No test/demo accounts ship in any seeder path mobile would hit** —
  `DemoDataSeeder` is a local/dev-only convenience, never run against
  production; nothing under `app/Http/Controllers/Api/` creates or
  depends on seeded data.

## Existing mobile API compatibility

There was no mobile API before this phase — `routes/api.php` did not
exist. Nothing here can have broken an existing contract, because none
existed. Going forward: an incompatible change to any endpoint above gets
a new `/api/v2/...` prefix group rather than breaking this one — see the
comment at the top of `routes/api.php`.
