<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\Job;
use App\Models\JobAttendance;
use App\Models\TimeEntry;
use App\Models\TimeTrackingSetting;
use App\Notifications\AttendanceRecorded;
use App\Services\Mobile\ElectricianJobAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The server side of Breeze-Electric's GPS check-in/check-out feature —
 * see `docs/mobile-attendance-api-contract.md` in that repo, which this
 * follows field-for-field so `ApiAttendanceRepository` needs no translation
 * layer between what it sends/receives and `JobSiteAttendance.toJson()`/
 * `.fromJson()`.
 *
 * Distinct from time-tracking's `TimeEntry`/`TimerSession`: those are
 * logged work hours with an approval workflow, job *or task* scoped. This
 * is raw GPS presence at a job site — no task, no approval, just "was this
 * technician here, and for how long" — which is what a supervisor or
 * manager actually wants from a check-in feature.
 */
class AttendanceController extends Controller
{
    use ApiResponses;

    /**
     * Worst horizontal accuracy, in metres, still trusted enough to become a
     * job's permanent site reference — same threshold the mobile app itself
     * uses for "is this fix trustworthy" (`poorAccuracyThreshold` in
     * `attendance_models.dart`). A fix too poor to verify a check-in against
     * is too poor to anchor the site at, too.
     */
    private const MAX_ACCURACY_FOR_SITE_CAPTURE = 50.0;

    /** How long after a first check-in it can still be undone. */
    private const UNDO_WINDOW_MINUTES = 30;

    /** Past this the record is flagged for a look: a fix this loose may be the next street over. */
    private const REVIEW_ACCURACY = 50.0;

    /** A point this far into the site's radius (of it) sits close to the edge. */
    private const REVIEW_EDGE_RATIO = 0.85;

    /** An upload this long after the event is flagged: it was saved offline for a long while. */
    private const REVIEW_DELAY_SECONDS = 1800;

    public function __construct(
        private readonly ElectricianJobAccess $access,
    ) {}

    /** Today's record for the signed-in technician at this job, or null. */
    public function index(Request $request, Job $job): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $attendance = JobAttendance::query()
            ->where('job_id', $job->id)
            ->where('user_id', $request->user()->id)
            ->where('date', $this->businessToday())
            ->first();

        return $this->ok($attendance ? $this->present($attendance, $job) : null);
    }

    /**
     * One GPS check-in/out record, in full — mobile's counterpart to web's
     * `TimeEntryController::showAttendance()`, for the Time Log Viewer's
     * day-detail screen to drill into. Same crew-visibility rule (own
     * record, or `viewCrew`), and a different, richer camelCase shape than
     * {@see present()} above — that one is the offline-sync check-in/out
     * contract (`docs/mobile-attendance-api-contract.md`); this is a
     * read-only display shape, matching web's own `AttendanceShow.tsx`.
     */
    public function show(Request $request, JobAttendance $attendance): JsonResponse
    {
        $canViewCrew = (bool) $request->user()->can('viewCrew', TimeEntry::class);
        abort_unless($canViewCrew || $attendance->user_id === $request->user()->id, 403);

        $attendance->load(['job', 'user']);
        $job = $attendance->job;

        return $this->ok([
            'id' => $attendance->id,
            'date' => $attendance->date->toDateString(),
            'status' => $attendance->status,
            'employee' => ['name' => $attendance->user?->name ?? 'Unknown'],
            'job' => $job ? [
                'id' => $job->id,
                'name' => $job->name,
                'client' => $job->client,
                'status' => $job->status,
            ] : null,
            'hours' => round($attendance->workingSeconds() / 3600, 2),
            'bankedSeconds' => $attendance->banked_seconds,
            'checkIn' => [
                'at' => $attendance->check_in_at?->toISOString(),
                'method' => $attendance->check_in_method,
                'accuracyMeters' => $attendance->check_in_accuracy !== null ? (float) $attendance->check_in_accuracy : null,
                'distanceMeters' => $attendance->check_in_distance_meters !== null ? (float) $attendance->check_in_distance_meters : null,
                'lat' => $attendance->check_in_lat !== null ? (float) $attendance->check_in_lat : null,
                'lng' => $attendance->check_in_lng !== null ? (float) $attendance->check_in_lng : null,
                'hasPhoto' => $attendance->check_in_photo_path !== null,
            ],
            'checkOut' => [
                'at' => $attendance->check_out_at?->toISOString(),
                'method' => $attendance->check_out_method,
                'accuracyMeters' => $attendance->check_out_accuracy !== null ? (float) $attendance->check_out_accuracy : null,
                'distanceMeters' => $attendance->check_out_distance_meters !== null ? (float) $attendance->check_out_distance_meters : null,
                'lat' => $attendance->check_out_lat !== null ? (float) $attendance->check_out_lat : null,
                'lng' => $attendance->check_out_lng !== null ? (float) $attendance->check_out_lng : null,
            ],
        ]);
    }

    /** The check-in selfie, when one exists — same crew-visibility rule as {@see show()}. */
    public function photo(Request $request, JobAttendance $attendance): StreamedResponse
    {
        $canViewCrew = (bool) $request->user()->can('viewCrew', TimeEntry::class);
        abort_unless($canViewCrew || $attendance->user_id === $request->user()->id, 403);
        abort_if($attendance->check_in_photo_path === null, 404);

        return ResponseFactory::streamDownload(
            fn () => print Storage::disk('local')->get($attendance->check_in_photo_path),
            "attendance-{$attendance->id}.jpg",
            ['Content-Type' => 'image/jpeg'],
            'inline',
        );
    }

    /** Every record for the signed-in technician today, across all jobs. */
    public function today(Request $request): JsonResponse
    {
        $attendance = JobAttendance::query()
            ->with('job')
            ->where('user_id', $request->user()->id)
            ->where('date', $this->businessToday())
            ->get();

        return $this->ok($attendance->map(fn (JobAttendance $a) => $this->present($a, $a->job))->values());
    }

    public function checkIn(Request $request, Job $job): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');
        abort_if($job->isLocked(), 409, 'This job is already completed and can no longer be checked into.');

        $data = $this->validatePoint($request);
        $today = $this->businessDate($data['occurred_at']);

        $row = JobAttendance::query()
            ->where('job_id', $job->id)
            ->where('user_id', $request->user()->id)
            ->where('date', $today)
            ->first() ?? new JobAttendance;

        // Already checked in — 200 with the existing record, not a 422 or a
        // second row. A check-in queued offline may reach the server twice.
        if ($row->exists && $row->isCheckedIn()) {
            return $this->ok($this->present($row, $job));
        }

        // A same-day re-check-in resumes the running total rather than
        // starting over: `workingSeconds()` folds whatever was banked
        // before *and* the cycle that just closed into one number, which
        // becomes the new banked total the moment this row is reused.
        $banked = $row->exists ? $row->workingSeconds() : 0;

        $photoPath = $row->check_in_photo_path;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store("attendance-photos/{$job->id}", 'local');
        }

        // A job with no site yet gets this check-in's own fix as its
        // reference point, first-one-in — see `establishJobLocationIfMissing()`.
        $job = $this->establishJobLocationIfMissing($job, $data);

        [$distance, $lat, $lng, $accuracy] = $this->resolvedFix($job, $data);

        $flag = $this->reviewFlag($job, $data, $distance, now()->getTimestamp() - $data['occurred_at']->getTimestamp());

        $row->fill([
            'job_id' => $job->id,
            'user_id' => $request->user()->id,
            'date' => $today,
            'status' => JobAttendance::STATUS_CHECKED_IN,
            'check_in_at' => $data['occurred_at'],
            'check_in_received_at' => now(),
            'check_in_lat' => $lat,
            'check_in_lng' => $lng,
            'check_in_accuracy' => $accuracy,
            'check_in_distance_meters' => $distance,
            'check_in_method' => $data['method'],
            'check_in_photo_path' => $photoPath,
            'check_out_at' => null,
            'check_out_lat' => null,
            'check_out_lng' => null,
            'check_out_accuracy' => null,
            'check_out_distance_meters' => null,
            'check_out_method' => null,
            'banked_seconds' => $banked,
            'client_id' => $data['client_id'] ?? $row->client_id,
            'review_flag' => $flag[0] ?? $row->review_flag,
            'review_reason' => $flag[1] ?? $row->review_reason,
        ]);
        $row->save();

        // An automatic one is the phone's doing, not theirs: tell them, so it is never a surprise.
        if ($data['method'] === JobAttendance::METHOD_AUTOMATIC) {
            $request->user()->notify(new AttendanceRecorded($job, AttendanceRecorded::CHECK_IN, $data['occurred_at']));
        }

        return $this->created($this->present($row, $job), 'Checked in.');
    }

    public function checkOut(Request $request, Job $job): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $data = $this->validatePoint($request);

        // The day it happened — or, for a shift that ran past midnight, whichever check-in is
        // still open on this job.
        $query = JobAttendance::query()->where('job_id', $job->id)->where('user_id', $request->user()->id);
        $row = (clone $query)->where('date', $this->businessDate($data['occurred_at']))->first()
            ?? (clone $query)->where('status', JobAttendance::STATUS_CHECKED_IN)->latest('date')->first();

        // Replayed from the offline queue after it already landed: same answer, no error.
        if ($row !== null && ! $row->isCheckedIn() && $row->check_out_at !== null
            && abs($row->check_out_at->getTimestamp() - $data['occurred_at']->getTimestamp()) <= 2) {
            return $this->ok($this->present($row, $job), 'Checked out.');
        }

        if ($row === null || ! $row->isCheckedIn()) {
            return $this->fail('You are not checked in at this job.', 422);
        }

        [$distance, $lat, $lng, $accuracy] = $this->resolvedFix($job, $data);

        // Never before the check-in it closes, whatever the phone's clock said.
        $at = $data['occurred_at']->max($row->check_in_at);
        $flag = $data['accuracy'] < 0
            ? ['no_gps', 'Checked out without a GPS fix.']
            : $this->reviewFlag($job, $data, $distance, now()->getTimestamp() - $at->getTimestamp());

        $row->fill([
            'status' => JobAttendance::STATUS_CHECKED_OUT,
            'check_out_at' => $at,
            'check_out_received_at' => now(),
            'check_out_lat' => $lat,
            'check_out_lng' => $lng,
            'check_out_accuracy' => $accuracy,
            'check_out_distance_meters' => $distance,
            'check_out_method' => $data['method'],
            'review_flag' => $row->review_flag ?? ($flag[0] ?? null),
            'review_reason' => $row->review_reason ?? ($flag[1] ?? null),
        ]);
        $row->save();

        if ($data['method'] === JobAttendance::METHOD_AUTOMATIC) {
            $request->user()->notify(new AttendanceRecorded($job, AttendanceRecorded::CHECK_OUT, $at));
        }

        return $this->ok($this->present($row, $job), 'Checked out.');
    }

    /**
     * "Undo check-in" — the first check-in of the day, taken back shortly after it was made
     * (an automatic one that was wrong). Later cycles are closed with a checkout instead.
     */
    public function undo(Request $request, Job $job): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $row = JobAttendance::query()
            ->where('job_id', $job->id)
            ->where('user_id', $request->user()->id)
            ->where('status', JobAttendance::STATUS_CHECKED_IN)
            ->latest('date')
            ->first();

        if ($row === null) {
            // Already gone — an undo replayed from the offline queue.
            return $this->ok(null, 'Nothing to undo.');
        }
        if ($row->banked_seconds > 0) {
            return $this->fail('You already worked on this job today — check out instead.', 422);
        }
        if ($row->check_in_at !== null && $row->check_in_at->lt(now()->subMinutes(self::UNDO_WINDOW_MINUTES))) {
            return $this->fail('It is too late to undo this check-in — check out instead.', 422);
        }

        $row->delete();
        $job->recordActivity('attendance_undone', "{$request->user()->name} undid a check-in.");

        return $this->ok(null, 'Check-in undone.');
    }

    /**
     * The technician's own word on a record: acknowledge an automatic check-in or checkout, add a
     * note, or report something wrong. Found by job and day, not by id, so it can be queued offline
     * before the record's id is known.
     */
    public function correct(Request $request, Job $job): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $data = $request->validate([
            'kind' => ['required', 'in:'.implode(',', AttendanceCorrection::KINDS)],
            'message' => ['nullable', 'string', 'max:2000', 'required_unless:kind,ack'],
            'date' => ['nullable', 'date'],
            'client_id' => ['nullable', 'string', 'max:64'],
        ], ['message.required_unless' => 'Write what happened.']);

        $row = JobAttendance::query()
            ->where('job_id', $job->id)
            ->where('user_id', $request->user()->id)
            ->when(isset($data['date']), fn ($q) => $q->where('date', Carbon::parse($data['date'])->toDateString()))
            ->latest('date')
            ->first();
        abort_if($row === null, 404, 'There is no check-in on that day to attach this to.');

        $correction = $data['kind'] === AttendanceCorrection::ACK
            ? AttendanceCorrection::firstOrCreate(
                ['job_attendance_id' => $row->id, 'user_id' => $request->user()->id, 'kind' => AttendanceCorrection::ACK],
                ['status' => 'resolved'],
            )
            // The same words from the same person on the same day are one report: a phone that
            // never heard the first reply sends it again, and must not file it twice.
            : AttendanceCorrection::firstOrCreate(
                [
                    'job_attendance_id' => $row->id,
                    'user_id' => $request->user()->id,
                    'kind' => $data['kind'],
                    'message' => trim($data['message']),
                ],
                ['status' => $data['kind'] === AttendanceCorrection::CORRECTION ? 'open' : 'resolved'],
            );

        return $this->created([
            'id' => $correction->id,
            'attendanceId' => $row->id,
            'kind' => $correction->kind,
            'message' => $correction->message,
            'status' => $correction->status,
        ], match ($data['kind']) {
            AttendanceCorrection::ACK => 'Acknowledged.',
            AttendanceCorrection::CORRECTION => 'Reported. Your foreman will review it.',
            default => 'Note added.',
        });
    }

    /** @return array{latitude: float, longitude: float, accuracy: float, method: string, client_id: string|null, occurred_at: Carbon} */
    private function validatePoint(Request $request): array
    {
        $validated = $request->validate([
            'point.latitude' => ['required', 'numeric', 'between:-90,90'],
            'point.longitude' => ['required', 'numeric', 'between:-180,180'],
            // Not unsigned — the app's own sentinel for "GPS failed but
            // check-out must still work anyway" is -1.
            'point.accuracy' => ['required', 'numeric'],
            'method' => ['required', 'in:manual,automatic,photo'],
            'client_id' => ['nullable', 'string', 'max:191'],
            'photo' => ['nullable', 'image', 'max:20480'],
            // When it happened on the phone. A check-in or checkout saved offline arrives later but
            // keeps its own time; a clock set ahead is clamped, one set a week back is refused.
            'occurred_at' => ['nullable', 'date', 'after_or_equal:-8 days'],
        ], [
            'occurred_at.after_or_equal' => 'That check-in is too old to record from the app. Ask your foreman to add it.',
        ]);

        return [
            'latitude' => (float) $validated['point']['latitude'],
            'longitude' => (float) $validated['point']['longitude'],
            'accuracy' => (float) $validated['point']['accuracy'],
            'method' => $validated['method'],
            'client_id' => $validated['client_id'] ?? null,
            'occurred_at' => isset($validated['occurred_at'])
                ? Carbon::parse($validated['occurred_at'])->min(now())
                : now(),
        ];
    }

    /**
     * A job with no site coordinates yet adopts the technician's own
     * check-in fix as its permanent reference point — first check-in in
     * wins. Never touches a job that already has a real location.
     *
     * Race-safe by construction: the `whereNull` guard means only the first
     * of two near-simultaneous check-ins against the same never-located job
     * actually updates a row (the second affects zero rows), so whichever
     * request loses the race simply proceeds against what the winner wrote
     * — no lost update, no overwrite.
     */
    private function establishJobLocationIfMissing(Job $job, array $data): Job
    {
        if ($job->latitude !== null && $job->longitude !== null) {
            return $job;
        }

        $hasUsableFix = $data['accuracy'] >= 0
            && $data['accuracy'] <= self::MAX_ACCURACY_FOR_SITE_CAPTURE
            && ! ($data['latitude'] === 0.0 && $data['longitude'] === 0.0);
        if (! $hasUsableFix) {
            return $job;
        }

        Job::query()
            ->whereKey($job->id)
            ->whereNull('latitude')
            ->whereNull('longitude')
            ->update([
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
            ]);

        return $job->fresh() ?? $job;
    }

    /**
     * The server, never the client, decides how far away the technician
     * actually was — `distance_meters` is always re-derived here from the
     * job's own stored coordinates, per the contract doc: "treat the
     * client's distance_meters as a claim, not a fact." A fix the app
     * itself marked unusable (accuracy -1, latitude/longitude 0/0 — its
     * sentinel for "GPS failed but check-in/out must still work") is
     * stored as null rather than as a fake point at sea off the coast of
     * Africa.
     *
     * @return array{0: float|null, 1: float|null, 2: float|null, 3: float|null} [distance, lat, lng, accuracy]
     */
    private function resolvedFix(Job $job, array $data): array
    {
        $hasFix = $data['accuracy'] >= 0
            && ! ($data['latitude'] === 0.0 && $data['longitude'] === 0.0);
        if (! $hasFix) {
            return [null, null, null, null];
        }

        $distance = null;
        if ($job->latitude !== null && $job->longitude !== null) {
            $distance = $this->haversineMeters(
                (float) $job->latitude,
                (float) $job->longitude,
                $data['latitude'],
                $data['longitude'],
            );
        }

        return [$distance, $data['latitude'], $data['longitude'], $data['accuracy']];
    }

    /** Great-circle distance in metres — same formula as the mobile app's
     *  own `LocationService.calculateDistance`, so a figure computed on
     *  both ends never quietly disagrees. */
    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371008.8;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 2);
    }

    /**
     * Why a check-in or checkout is worth a person's look, or null when it is plain.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string}|null
     */
    private function reviewFlag(Job $job, array $data, ?float $distance, int $delaySeconds): ?array
    {
        if ($data['accuracy'] >= 0 && $data['accuracy'] > self::REVIEW_ACCURACY) {
            return ['low_accuracy', 'GPS accuracy was only '.round($data['accuracy']).' m.'];
        }

        $radius = (float) ($job->geofence_radius ?: 100);
        if ($distance !== null && $radius > 0 && $distance > $radius * self::REVIEW_EDGE_RATIO) {
            return ['near_boundary', round($distance).' m from the site centre — near the edge of the '.round($radius).' m boundary.'];
        }

        if ($delaySeconds > self::REVIEW_DELAY_SECONDS) {
            return ['delayed_sync', 'Saved on the phone and sent '.round($delaySeconds / 60).' minutes later.'];
        }

        return null;
    }

    /** The business calendar day an instant falls on. */
    private function businessDate(Carbon $at): string
    {
        return $at->copy()->timezone(TimeTrackingSetting::current()->timezone)->toDateString();
    }

    /** The business calendar day, per the shared time-tracking setting — the
     *  same convention `TimerService` uses for "today", not the server's
     *  own UTC date. */
    private function businessToday(): string
    {
        return now()->timezone(TimeTrackingSetting::current()->timezone)->toDateString();
    }

    /**
     * @return array<string, mixed>
     *
     * `$job` is optional only because a couple of call sites historically
     * had no cheap way to supply it; every current caller passes it. When
     * present, the job's own (possibly just-established — see
     * `establishJobLocationIfMissing()`) coordinates are echoed back so the
     * app can adopt them into its in-memory site immediately, without
     * waiting for the next job-list refetch.
     */
    private function present(JobAttendance $attendance, ?Job $job = null): array
    {
        return [
            'id' => (string) $attendance->id,
            'job_id' => $attendance->job_id,
            'status' => $attendance->status,
            'check_in_at' => $attendance->check_in_at?->toISOString(),
            'check_in_point' => $attendance->check_in_lat !== null ? [
                'latitude' => (float) $attendance->check_in_lat,
                'longitude' => (float) $attendance->check_in_lng,
                'accuracy' => (float) $attendance->check_in_accuracy,
                'recorded_at' => $attendance->check_in_at?->toISOString(),
            ] : null,
            'check_in_distance_meters' => $attendance->check_in_distance_meters !== null
                ? (float) $attendance->check_in_distance_meters
                : null,
            'check_in_method' => $attendance->check_in_method,
            'check_in_photo_path' => $attendance->check_in_photo_path,
            'check_out_at' => $attendance->check_out_at?->toISOString(),
            'check_out_point' => $attendance->check_out_lat !== null ? [
                'latitude' => (float) $attendance->check_out_lat,
                'longitude' => (float) $attendance->check_out_lng,
                'accuracy' => (float) $attendance->check_out_accuracy,
                'recorded_at' => $attendance->check_out_at?->toISOString(),
            ] : null,
            'check_out_distance_meters' => $attendance->check_out_distance_meters !== null
                ? (float) $attendance->check_out_distance_meters
                : null,
            'check_out_method' => $attendance->check_out_method,
            // Everything closed before whatever session is currently open —
            // `JobSiteAttendance.workingDuration` on the mobile side adds
            // its own live "now - checkInAt" on top of this; sending the
            // already-summed `workingSeconds()` here would double-count it.
            'banked_duration_seconds' => $attendance->banked_seconds,
            'sync_state' => 'synced',
            'review_flag' => $attendance->review_flag,
            'review_reason' => $attendance->review_reason,
            'recorded_offline' => $attendance->checkInWasOffline() || $attendance->checkOutWasOffline(),
            'job_latitude' => $job && $job->latitude !== null ? (float) $job->latitude : null,
            'job_longitude' => $job && $job->longitude !== null ? (float) $job->longitude : null,
            'job_geofence_radius' => $job?->geofence_radius,
        ];
    }
}
