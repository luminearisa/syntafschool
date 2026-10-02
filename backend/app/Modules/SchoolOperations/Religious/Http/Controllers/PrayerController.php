<?php

namespace App\Modules\SchoolOperations\Religious\Http\Controllers;

use App\Modules\SchoolOperations\Religious\Application\Services\{CongregationService, DutyRosterService, MosqueBookingService, PrayerScheduleService};
use App\Modules\SchoolOperations\Religious\Models\{CongregationalPrayer, PrayerSchedule, ReligiousDutyRoster};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Jadwal shalat, sumber waktu, jamaah, dan roster tugas (§10-§21). */
class PrayerController extends Controller
{
    public function __construct(
        private readonly PrayerScheduleService $prayers,
        private readonly CongregationService $congregations,
        private readonly DutyRosterService $rosters,
    ) {
    }

    public function prayerTypes(Request $request): JsonResponse
    {
        $request->user()->can('religious.prayer.view') || abort(403);

        return response()->json(['data' => $this->prayers->prayerTypes()]);
    }

    public function sources(Request $request): JsonResponse
    {
        $request->user()->can('religious.prayer.view') || abort(403);

        return response()->json(['data' => [
            'sources' => $this->prayers->sources(),
            'provider' => $this->prayers->providerCapabilities(),
        ]]);
    }

    /** Jadwal satu hari (§11). */
    public function index(Request $request): JsonResponse
    {
        $request->user()->can('religious.prayer.view') || abort(403);

        return response()->json(['data' => $this->prayers->forDate(
            (string) $request->input('date', now()->toDateString()),
            $request->integer('mosque_id') ?: null,
            $request->integer('campus_id') ?: null,
        )]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can('religious.prayer.schedule.manage') || abort(403);
        $schedule = $this->prayers->upsertSchedule($request->validate([
            'schedule_date' => ['required', 'date'],
            'mosque_id' => ['nullable', 'integer'], 'campus_id' => ['nullable', 'integer'],
            'prayer_type' => ['required', 'string'],
            'adhan_time' => ['nullable'], 'iqamah_time' => ['nullable'],
            'start_time' => ['nullable'], 'end_time' => ['nullable'],
            'status' => ['nullable', 'string'], 'notes' => ['nullable', 'string'],
        ]), 'manual');

        return response()->json(['data' => $schedule], 201);
    }

    /** Bangkitkan jadwal dari penyedia waktu shalat (§12). */
    public function generate(Request $request): JsonResponse
    {
        $request->user()->can('religious.prayer.schedule.manage') || abort(403);
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'mosque_id' => ['nullable', 'integer'], 'campus_id' => ['nullable', 'integer'],
        ]);

        return response()->json(['data' => $this->prayers->generate(
            $validated['date'],
            $request->only(['mosque_id', 'campus_id', 'latitude', 'longitude'])
        )]);
    }

    public function generateRange(Request $request): JsonResponse
    {
        $request->user()->can('religious.prayer.schedule.manage') || abort(403);
        $validated = $request->validate([
            'from' => ['required', 'date'], 'to' => ['required', 'date'],
            'mosque_id' => ['nullable', 'integer'], 'campus_id' => ['nullable', 'integer'],
        ]);

        return response()->json(['data' => $this->prayers->generateRange(
            $validated['from'], $validated['to'], $request->only(['mosque_id', 'campus_id'])
        )]);
    }

    /** Riwayat versi jadwal (§13). */
    public function versions(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.prayer.view') || abort(403);

        return response()->json(['data' => [
            'schedule' => PrayerSchedule::query()->findOrFail($id)->only([
                'id', 'schedule_date', 'prayer_type', 'adhan_time', 'iqamah_time', 'version_number', 'status',
            ]),
            'versions' => $this->prayers->versions($id),
        ]]);
    }

    public function override(Request $request): JsonResponse
    {
        $request->user()->can('religious.prayer.schedule.manage') || abort(403);
        $validated = $request->validate([
            'date' => ['required', 'date'], 'prayer_type' => ['required', 'string'],
            'reason' => ['required', 'string'],
            'adhan_time' => ['nullable'], 'iqamah_time' => ['nullable'], 'end_time' => ['nullable'],
        ]);

        return response()->json(['data' => $this->prayers->override(
            $validated['date'], $validated['prayer_type'], $request->only(['adhan_time', 'iqamah_time', 'end_time']),
            $validated['reason'], $request->only(['mosque_id', 'campus_id'])
        )], 201);
    }

    // ── Jamaah (§15-§21) ───────────────────────────────────────────

    public function congregations(Request $request): JsonResponse
    {
        $request->user()->can('religious.prayer.view') || abort(403);

        return response()->json(['data' => $this->congregations->forDate(
            (string) $request->input('date', now()->toDateString()),
            $request->integer('mosque_id') ?: null,
        )]);
    }

    public function storeCongregation(Request $request): JsonResponse
    {
        $request->user()->can('religious.prayer.manage') || abort(403);

        return response()->json(['data' => $this->congregations->ensure($request->validate([
            'mosque_id' => ['required', 'integer'], 'prayer_type' => ['required', 'string'],
            'congregation_date' => ['nullable', 'date'], 'imam_id' => ['nullable', 'integer'],
            'muadzin_id' => ['nullable', 'integer'], 'start_time' => ['nullable'],
            'end_time' => ['nullable'], 'participant_estimate' => ['nullable', 'integer'],
            'roster_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string'],
        ]))], 201);
    }

    public function assignCongregation(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.prayer.manage') || abort(403);
        $validated = $request->validate([
            'role' => ['required', 'string', 'in:IMAM,MUADZIN'], 'profile_id' => ['required', 'integer'],
        ]);

        return response()->json(['data' => $this->congregations->assign($id, $validated['role'], (int) $validated['profile_id'])]);
    }

    public function substituteCongregation(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.prayer.manage') || abort(403);
        $validated = $request->validate([
            'role' => ['required', 'string', 'in:IMAM,MUADZIN'], 'profile_id' => ['required', 'integer'],
            'reason' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->congregations->substitute(
            $id, $validated['role'], (int) $validated['profile_id'], $request->only(['reason'])
        )]);
    }

    public function completeCongregation(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.prayer.manage') || abort(403);

        return response()->json(['data' => $this->congregations->complete($id, (int) $request->input('participant_actual', 0))]);
    }

    // ── Roster (§19, §20) ──────────────────────────────────────────

    public function rosters(Request $request): JsonResponse
    {
        $request->user()->can('religious.roster.view') || abort(403);

        return response()->json(['data' => ReligiousDutyRoster::query()
            ->when($request->integer('mosque_id'), fn ($q, $id) => $q->where('mosque_id', $id))
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn ($r): array => $r->only(['id', 'code', 'name', 'roster_type', 'effective_from', 'effective_until', 'status']))->all()]);
    }

    public function storeRoster(Request $request): JsonResponse
    {
        $request->user()->can('religious.roster.manage') || abort(403);

        return response()->json(['data' => $this->rosters->create($request->validate([
            'name' => ['required', 'string'], 'roster_type' => ['nullable', 'string'],
            'mosque_id' => ['nullable', 'integer'], 'campus_id' => ['nullable', 'integer'],
            'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]))], 201);
    }

    public function storeRosterAssignment(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.roster.manage') || abort(403);
        $assignment = $this->rosters->addAssignment($id, $request->validate([
            'duty_date' => ['required', 'date'], 'prayer_type' => ['nullable', 'string'],
            'duty_role' => ['nullable', 'string'], 'personnel_id' => ['nullable', 'integer'],
            'personnel_name' => ['nullable', 'string'], 'mosque_id' => ['nullable', 'integer'],
            'area_id' => ['nullable', 'integer'], 'starts_at' => ['nullable'], 'ends_at' => ['nullable'],
            'override_reason' => ['nullable', 'string'], 'notes' => ['nullable', 'string'],
        ]), (bool) $request->boolean('allow_override'));

        return response()->json(['data' => $assignment], 201);
    }

    public function generateRoster(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.roster.manage') || abort(403);
        $validated = $request->validate([
            'pattern' => ['required', 'array'], 'weeks' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        return response()->json(['data' => $this->rosters->generateWeekly($id, $validated['pattern'], (int) ($validated['weeks'] ?? 1))]);
    }

    public function publishRoster(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.roster.publish') || abort(403);

        return response()->json(['data' => $this->rosters->publish($id)]);
    }

    /** Roster satu hari (§19, §65). */
    public function dutyOfDay(Request $request): JsonResponse
    {
        $request->user()->can('religious.roster.view') || abort(403);

        return response()->json(['data' => $this->rosters->forDate(
            (string) $request->input('date', now()->toDateString()),
            $request->integer('mosque_id') ?: null,
        )]);
    }

    /** Tugas keagamaan siswa (§54). */
    public function studentDuty(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.roster.manage') || abort(403);

        return response()->json(['data' => $this->rosters->assignStudentDuty($id, $request->all())], 201);
    }
}
