# Breeze — AI Electrical Takeoff

A Laravel application for AI-powered electrical takeoff, with a React front end
served through Inertia.

Laravel is the **orchestration layer**: it stores the drawing, hands it to an
external AI service, keeps that response verbatim as the audit record, then walks
a reviewer through approving it and turns the approved result into a job and a
priced estimate.

Nothing is simulated. There is no fixture data behind a run — with no AI service
configured, an upload fails loudly rather than inventing numbers.

---

## Quick start

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed        # schema + the demo workspace

# Point at the AI takeoff service (required before any upload will run):
#   AI_API_BASE_URL=https://your-ai-host
#   AI_API_KEY=...

composer run dev                  # server, queue, logs and Vite together
```

A **queue worker must be running** for uploads to be analysed
(`composer run dev` starts one). If none is up, the processing screen advances the
run itself on each poll, so a run never silently stalls.

Then open <http://localhost:8000> and sign in with the seeded account — the login
form has a **Fill** button for it:

```text
demo@breeze.ai / breeze123
```

Other commands:

```bash
php artisan test        # 55 feature tests
npm run build           # production assets
npm run typecheck       # tsc --build
npm run lint            # ESLint (React Compiler rules enabled)
./vendor/bin/pint       # PHP formatting
```

---

## The AI service

The model backend lives outside this application. Everything about the contract
is configuration ([`config/ai.php`](config/ai.php)), so a differently shaped
backend can be pointed at without touching application code.

| Variable                 | Default                          | Purpose                                |
| ------------------------ | -------------------------------- | -------------------------------------- |
| `AI_API_BASE_URL`        | —                                | Required. Base URL of the AI service   |
| `AI_API_KEY`             | —                                | Sent as a bearer token by default      |
| `AI_API_AUTH_MODE`       | `bearer`                         | `bearer`, or `header` + `AI_API_KEY_HEADER` |
| `AI_API_SUBMIT_PATH`     | `api/v1/takeoff`                 | Multipart upload of the drawing        |
| `AI_API_STATUS_PATH`     | `api/v1/takeoff/:job/status`     | Poll target; `:job` is the returned id |
| `AI_API_RESULT_PATH`     | `api/v1/takeoff/:job/result`     | The finished analysis                  |
| `AI_API_CANCEL_PATH`     | `api/v1/takeoff/:job/cancel`     | Best-effort cancel                     |
| `AI_API_POLL_INTERVAL`   | `3`                              | Seconds between polls                  |
| `AI_API_WEBHOOK_ENABLED` | `false`                          | Push status instead of polling         |
| `AI_API_WEBHOOK_SECRET`  | —                                | HMAC secret for `POST /ai/webhook/{aiJob}` |

Expected shapes:

```jsonc
// POST {submit}  -> 201
{ "job_id": "aij_123", "status": "queued", "progress": 0 }

// GET {status}   -> 200
{ "status": "processing", "progress": 60, "stage": "detect_symbols" }

// GET {result}   -> 200
{
  "model_version": "breeze-vision-2.4",
  "pages":   [{ "number": 1, "width": 3024, "height": 2160 }],
  "symbols": [{
    "id": "crop_0001", "name": "light fixture", "page": 1, "count": 1,
    "confidence": 0.9, "bbox": [913, 906, 25, 25],
    "sources": ["template", "vector"],
    "pipeline": ["generated", "validated", "classified", "fusion", "final_json"],
    "known": true
  }]
}
```

[`AiResponseNormaliser`](app/Services/Ai/AiResponseNormaliser.php) accepts common
variations — `bbox` as an object, `confidence` as a percentage, `sources` as a map,
detections under `data.symbols` — and a response with no usable detections fails
the run rather than producing an empty takeoff.

---

## Tech stack

| Concern         | Choice                                             |
| --------------- | -------------------------------------------------- |
| Framework       | Laravel 12 (PHP 8.2+)                              |
| Front end       | React 19 + TypeScript (strict) via Inertia 2       |
| Build           | Vite 8 + `laravel-vite-plugin`                     |
| Styling         | Tailwind CSS v4 (CSS-first `@theme` design tokens) |
| Database        | SQLite by default; any Laravel driver works        |
| Auth            | Laravel session guard, throttled sign-in           |
| Animation       | Framer Motion                                      |
| Icons           | Lucide React                                       |
| Uploads         | React Dropzone → real multipart POST               |
| Client state    | Zustand (upload queue + UI chrome only)            |
| Utilities       | clsx + tailwind-merge, date-fns                    |
| Realtime        | Laravel Reverb + Echo — see [`docs/realtime.md`](docs/realtime.md) |

There is no client-side router, no Axios and no React Query: Laravel owns
routing and every page receives its data as Inertia props.

---

## Routes

Defined in [`routes/web.php`](routes/web.php). Public routes sit behind the
`guest` middleware, everything else behind `auth`.

| Method | Path                            | Name                 | Screen / action                        |
| ------ | ------------------------------- | -------------------- | -------------------------------------- |
| GET    | `/login`                        | `login`              | Sign in                                |
| POST   | `/login`                        | —                    | Authenticate (throttled, 5 tries)      |
| POST   | `/logout`                       | `logout`             | Sign out                               |
| GET    | `/forgot-password`              | `password.request`   | Request a reset link                   |
| POST   | `/forgot-password`              | `password.email`     | Accept the request                     |
| GET    | `/`, `/home`                    | `home`               | Dashboard                              |
| GET    | `/ai-takeoff`                   | `takeoffs.index`     | Takeoff history (search/filter/sort)   |
| GET    | `/ai-takeoff/upload`            | `uploads.create`     | Upload screen                          |
| POST   | `/ai-takeoff/upload`            | `uploads.store`      | Store the drawing set, open a run      |
| DELETE | `/takeoffs/{project}`           | `takeoffs.destroy`   | Soft-delete a takeoff                  |
| POST   | `/takeoffs/{project}/restore`   | `takeoffs.restore`   | Undo that delete                       |
| GET    | `/processing/{project}`         | `processing.show`    | Progress for an open run               |
| GET    | `/processing/{project}/status`  | `processing.status`  | JSON poll target; nudges stalled runs  |
| POST   | `/processing/{project}/cancel`  | `processing.cancel`  | Cancel the run (tells the AI service)  |
| POST   | `/processing/{project}/restart` | `processing.restart` | Resubmit the same drawing              |
| POST   | `/ai/webhook/{aiJob}`           | `ai.webhook`         | Signed callback from the AI service    |
| GET    | `/results`, `/results/{project}`| `results.*`          | Hands over to review or final symbols  |
| GET    | `/reviews/{result}`             | `reviews.show`       | **AI Review** — every detection        |
| GET    | `/reviews/{result}/original.json`| `reviews.original`  | The untouched AI response              |
| GET    | `/reviews/{result}/crops/{review}`| `reviews.crop`     | Cropped symbol image                   |
| POST   | `/reviews/{result}/symbols/{review}/…`| `reviews.*`    | approve, reject, reset, count, rename, note, split |
| POST   | `/reviews/{result}/merge`       | `reviews.merge`      | Merge a selection into one symbol      |
| POST   | `/reviews/{result}/bulk`        | `reviews.bulk`       | Decide a whole selection               |
| POST   | `/reviews/{result}/finalise`    | `reviews.finalise`   | Build `final_response.json`            |
| POST   | `/reviews/{result}/reopen`      | `reviews.reopen`     | Reopen a signed-off takeoff            |
| GET    | `/takeoffs/{result}/final`      | `finals.show`        | **Final symbol table** + bill of quantities |
| GET    | `/takeoffs/{result}/final/export/{format}`| `finals.export`| JSON / CSV / XLSX                  |
| GET    | `/takeoffs/{result}/annotated.pdf`| `finals.annotated` | Drawing stamped with the decisions     |
| POST   | `/takeoffs/{result}/job`        | `finals.job`         | Create the job from the final JSON     |
| POST   | `/takeoffs/{result}/estimate`   | `finals.estimate`    | Price the final JSON                   |
| GET    | `/jobs`                         | `jobs.index`         | Jobs (search/filter/pagination)        |
| GET    | `/jobs/{job}`                   | `jobs.show`          | Job detail hub                         |
| POST   | `/jobs/{job}/assignments`       | `jobs.assignments.store`| Staff a role (releases the incumbent) |
| DELETE | `/jobs/{job}/assignments/{assignment}`| `jobs.assignments.destroy`| Release, keeping the history |
| GET    | `/estimates/{estimate}`         | `estimates.show`     | Estimate detail, editable              |
| PUT    | `/estimates/{estimate}`         | `estimates.update`   | Client, status, markup, tax, notes     |
| POST   | `/estimates/{estimate}/items`   | `estimates.items.store`| Add a line                           |
| GET    | `/estimates/{estimate}/pdf`     | `estimates.pdf`      | Client-ready PDF                       |
| GET    | `/empty`, `/error`              | `states.*`           | Canonical empty and error screens      |

The full list — including job notes, attachments, archiving, bulk actions and the
drawer's placeholder modules — is in `routes/web.php`.

404s, 403s and 500s render through the React state screens — see the
`withExceptions` block in [`bootstrap/app.php`](bootstrap/app.php).

---

## Data model

`php artisan migrate --seed` builds the schema and seeds the workspace the UI was
designed against — 24 jobs, 24 takeoff projects, and one fully detailed result
set on *Westside Commercial Complex*.

| Table                | Model              | Holds                                              |
| -------------------- | ------------------ | -------------------------------------------------- |
| `users`              | `User`             | Accounts; `role` + derived avatar `initials`       |
| `foremen`            | `Foreman`          | Job supervisors                                    |
| `work_jobs`          | `Job`              | Electrical jobs (soft-deletable)                   |
| `projects`           | `Project`          | Takeoff runs — history rows *and* results          |
| `drawing_sheets`     | `DrawingSheet`     | Sheets in a run                                    |
| `detected_symbols`   | `DetectedSymbol`   | Counted devices with confidence                    |
| `project_metrics`    | `ProjectMetric`    | Headline figures on the results dashboard          |
| `project_activities` | `ProjectActivity`  | Pipeline event log                                 |
| `uploads`            | `Upload`           | Stored files, linked to their run                  |
| `app_notifications`  | `AppNotification`  | In-app notifications behind the header bell        |
| `feed_items`         | `FeedItem`         | Rows for the icon-list panels, keyed by `scope`    |
| `performance_points` | `PerformancePoint` | Monthly series behind the dashboard chart          |
| `ai_jobs`            | `AiJob`            | One submission to the AI service; live run state   |
| `ai_results`         | `AiResult`         | The AI response verbatim, plus the reviewed one     |
| `symbol_reviews`     | `SymbolReview`     | One detection, and the reviewer's verdict on it     |
| `final_symbols`      | `FinalSymbol`      | Approved symbols aggregated by name — the takeoff   |
| `approval_histories` | `ApprovalHistory`  | Append-only audit trail for a takeoff              |
| `estimate_items`     | `EstimateItem`     | Editable estimate lines by category                |
| `job_assignments`    | `JobAssignment`    | Role-based staffing; released rows are the history  |

Two naming notes worth knowing:

- The jobs table is **`work_jobs`**, because Laravel reserves `jobs` for the
  queue driver. The model is still `App\Models\Job`.
- Notifications live in **`app_notifications`**, leaving `notifications` free for
  Laravel's database notification channel.

Icons for feed rows and dashboard tiles are stored as *keys* (`"file-text"`,
`"bot"`, …) and resolved client-side by
[`resources/js/lib/icons.tsx`](resources/js/lib/icons.tsx) — a database row can't
carry a React component.

---

## How a takeoff runs

1. **Upload** — the dropzone validates locally, then posts the whole queue in one
   multipart request. `StoreUploadRequest` revalidates extensions, per-file size
   and file count against `config/takeoff.php`.
2. **Store** — files land on the configured disk, a `Project` opens with status
   `processing`, and an `AiJob` records the run. `ProcessTakeoffRun` is queued.
3. **Submit** — the worker renders page previews, uploads the drawing to the AI
   service and stores the id it returns.
4. **Poll** — the worker walks the run to completion (or the service pushes to the
   signed webhook). The processing screen polls *Laravel*, never the AI service.
5. **Ingest** — the response is written to `original_response.json` and
   `ai_results.original_payload` **before** anything is derived from it, then
   normalised into one `symbol_reviews` row per detection, with a crop cut from the
   rendered page. The run's owner is notified that a review is waiting.
6. **Review** — on the AI Review screen every detection can be approved, rejected,
   recounted, renamed, merged, split or annotated. Each action writes to the
   database and appends an audit row; the AI response is never edited.
7. **Finalise** — `FinalJsonBuilder` composes a *new* document,
   `final_response.json`, from the approved rows only: reviewed names, reviewed
   counts, plus a bill of quantities. Rejected detections appear solely as an audit
   list. The assigned estimator is notified.
8. **Job** — created from `final_response.json` alone. Its symbol counts, BOQ and
   metadata are copied onto the record, so a later re-review cannot silently change
   what the crew is building to.
9. **Estimate** — priced from the same document using the rate catalog in
   [`config/estimating.php`](config/estimating.php): device lines, consumables,
   labor per family and an equipment allowance. Every line, rate, markup and tax
   figure stays editable, and the totals are recomputed from the lines on each
   save. The project manager is notified.
10. **Staffing** — estimator, project manager, foreman, electrician and reviewer
    are assigned per job; releasing someone keeps the row as history.

Two rules hold throughout: **the AI response is evidence, not truth**, and
**quantities only ever flow forwards** — review → final JSON → job → estimate.

---

## Project structure

```text
app/
├─ Http/
│  ├─ Controllers/       one per screen or module
│  ├─ Middleware/        HandleInertiaRequests (shared props)
│  ├─ Requests/          validation: login, jobs, uploads
│  └─ Resources/         camelCase prop shapes for the React types
├─ Models/               Eloquent models
├─ Jobs/                 ProcessTakeoffRun (submit → poll → ingest)
├─ Events/ Listeners/    takeoff processed / failed, review finalised, estimate
├─ Notifications/        mail + in-app, via a small custom channel
├─ Policies/             takeoff ownership; a finalised review is read-only
└─ Services/
   ├─ Ai/                client, normaliser, orchestrator, artefacts, crops
   ├─ Takeoff/           final JSON, bill of quantities, job + estimate builders
   └─ Export/            CSV / XLSX, annotated PDF, estimate PDF
config/ai.php            AI service contract, artefacts, estimating defaults
config/estimating.php    rate catalog used to price a reviewed takeoff
config/takeoff.php       upload limits, pipeline stages, page size
database/
├─ migrations/
└─ seeders/DemoDataSeeder.php
resources/
├─ js/
│  ├─ app.tsx            Inertia entry point
│  ├─ Pages/             one component per Inertia page
│  ├─ components/        common / layout / upload / processing / dashboard /
│  │                     results / history / jobs / review / estimates
│  ├─ constants/         routes, navigation, filter options, upload limits
│  ├─ hooks/             useFileUpload, useProcessingPipeline, …
│  ├─ lib/icons.tsx      server icon keys → Lucide glyphs
│  ├─ store/             Zustand: upload queue + UI chrome
│  ├─ styles/            Tailwind entry + design tokens
│  ├─ types/             domain types + Inertia prop contracts
│  └─ utils/             cn(), formatters, file validation, status mapping
└─ views/app.blade.php   the only Blade file — the Inertia root
routes/web.php
tests/Feature/           55 tests: auth, history, jobs, the AI run, review→estimate
```

`@/*` is aliased to `resources/js/*` in both Vite and TypeScript.

---

## Server-driven lists

History and Jobs filter, sort and paginate **in the database**, driven by the
query string, so every view is a shareable URL and the browser never holds the
full table. The client debounces search input and issues Inertia visits with
`preserveState` so focus and scroll survive the round trip.

Deletes are soft, which is what makes the "Undo" affordance a real restore rather
than a re-insert.

---

## Design system

Unchanged from the original front end. All tokens live in
[`resources/js/styles/index.css`](resources/js/styles/index.css) under `@theme`,
so no component hardcodes a colour, radius, shadow or font size.

- **Palette** — cyan brand (`--color-brand: #33E3FF`) on a deep navy/ocean
  gradient backdrop, with translucent "glass" surfaces.
- **Type scale** — 18 px body, 24 px card titles, 32 px section headings.
- **Radii** — `rounded-panel` (10 px), `rounded-dropzone` (12 px),
  `rounded-card` (16 px), `rounded-pill` (20 px).
- **Surface utilities** — `glass`, `glass-strong`, `grad-ocean`,
  `grad-ocean-solid`, `grad-midnight`, `grad-spotlight`, `grad-sidebar`,
  `shimmer`, `blueprint-grid`.
- **Status tones** — a single `Tone` union (`brand | success | warning | danger |
  info | neutral`) drives badges, chips, progress bars and alerts.

### Responsive behaviour

| Breakpoint    | Layout                                                   |
| ------------- | -------------------------------------------------------- |
| `< 640px`     | Single column, logo mark only, off-canvas sidebar drawer |
| `640–1023px`  | Two-column card grids, collapsible search row            |
| `1024–1279px` | Persistent (collapsible) sidebar rail                    |
| `1280px+`     | Split upload/results layouts, full action labels         |
| Ultra-wide    | Content capped at `--container-ultra` (1920 px), centred |

Wide content (tables) scrolls inside its own container — the page body never
scrolls horizontally.

---

## Known gaps

- **The AI service must be supplied.** This app orchestrates it; it does not
  contain a vision model. With `AI_API_BASE_URL` unset, the upload screen says so
  and refuses to open a run.
- **Rates are defaults.** `config/estimating.php` ships a plausible price book to
  get a first-pass estimate; replace it with your own, or edit the lines on the
  estimate.
- **No password-reset screen.** `POST /forgot-password` always reports success
  and sends no mail, because the design has no `/reset-password/{token}` screen
  to land on. Wiring Laravel's password broker means adding that screen.
- **Profile and Settings** in the account menu are inert.
- **Notifications are read-only** — the bell lists them and deep-links to the
  screen that needs attention, but nothing marks them read.
