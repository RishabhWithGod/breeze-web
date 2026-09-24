<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\JobAttendance;
use App\Models\TimeEntry;
use App\Models\TimeTrackingSetting;
use App\Services\Mobile\ElectricianJobAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return $this->ok($attendance ? $this->present($attendance) : null);
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
            ->where('user_id', $request->user()->id)
            ->where('date', $this->businessToday())
            ->get();

        return $this->ok($attendance->map(fn (JobAttendance $a) => $this->present($a))->values());
    }

    public function checkIn(Request $request, Job $job): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');
        abort_if($job->isLocked(), 409, 'This job is already completed and can no longer be checked into.');

        $data = $this->validatePoint($request);
        $today = $this->businessToday();

        $row = JobAttendance::query()
            ->where('job_id', $job->id)
            ->where('user_id', $request->user()->id)
            ->where('date', $today)
            ->first() ?? new JobAttendance();

        // Already checked in — 200 with the existing record, not a 422 or a
        // second row. A check-in queued offline may reach the server twice.
        if ($row->exists && $row->isCheckedIn()) {
            return $this->ok($this->present($row));
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

        [$distance, $lat, $lng, $accuracy] = $this->resolvedFix($job, $data);

        $row->fill([
            'job_id' => $job->id,
            'user_id' => $request->user()->id,
            'date' => $today,
            'status' => JobAttendance::STATUS_CHECKED_IN,
            'check_in_at' => now(),
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
        ]);
        $row->save();

        return $this->created($this->present($row), 'Checked in.');
    }

    public function checkOut(Request $request, Job $job): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $data = $this->validatePoint($request);

        $row = JobAttendance::query()
            ->where('job_id', $job->id)
            ->where('user_id', $request->user()->id)
            ->where('date', $this->businessToday())
            ->first();

        if ($row === null || ! $row->isCheckedIn()) {
            return $this->fail('You are not checked in at this job.', 422);
        }

        [$distance, $lat, $lng, $accuracy] = $this->resolvedFix($job, $data);

        $row->fill([
            'status' => JobAttendance::STATUS_CHECKED_OUT,
            'check_out_at' => now(),
            'check_out_lat' => $lat,
            'check_out_lng' => $lng,
            'check_out_accuracy' => $accuracy,
            'check_out_distance_meters' => $distance,
            'check_out_method' => $data['method'],
        ]);
        $row->save();

        return $this->ok($this->present($row), 'Checked out.');
    }

    /** @return array{latitude: float, longitude: float, accuracy: float, method: string, client_id: string|null} */
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
        ]);

        return [
            'latitude' => (float) $validated['point']['latitude'],
            'longitude' => (float) $validated['point']['longitude'],
            'accuracy' => (float) $validated['point']['accuracy'],
            'method' => $validated['method'],
            'client_id' => $validated['client_id'] ?? null,
        ];
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

    /** The business calendar day, per the shared time-tracking setting — the
     *  same convention `TimerService` uses for "today", not the server's
     *  own UTC date. */
    private function businessToday(): string
    {
        return now()->timezone(TimeTrackingSetting::current()->timezone)->toDateString();
    }

    /** @return array<string, mixed> */
    private function present(JobAttendance $attendance): array
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
        ];
    }
}
