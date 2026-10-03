<?php

namespace App\Modules\SchoolOperations\Religious\Http\Controllers;

use App\Modules\SchoolOperations\Religious\Application\Services\PrayerAttendanceService;
use App\Modules\SchoolOperations\Religious\Models\PrayerAttendancePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Presensi shalat, kebijakan, jendela, penyesuaian, dan penutupan harian (§22-§26, §70, §90). */
class AttendanceController extends Controller
{
    public function __construct(private readonly PrayerAttendanceService $attendance) {}

    /** Catat presensi (§22, §90). Idempoten per orang+shalat+tanggal. */
    public function store(Request $request): JsonResponse
    {
        $request->user()->can('religious.attendance.record') || abort(403);
        $validated = $request->validate([
            'prayer_type' => ['required', 'string'],
            'attendance_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string'],
            'participant_type' => ['nullable', 'string'],
            'student_id' => ['nullable', 'integer'], 'employee_id' => ['nullable', 'integer'],
            'mosque_id' => ['nullable', 'integer'], 'campus_id' => ['nullable', 'integer'],
            'prayer_schedule_id' => ['nullable', 'integer'],
            'congregational_prayer_id' => ['nullable', 'integer'],
            'dormitory_id' => ['nullable', 'integer'], 'grade_level' => ['nullable', 'string'],
            'class_id' => ['nullable', 'integer'], 'organization_id' => ['nullable', 'integer'],
            'method' => ['nullable', 'string'], 'device_code' => ['nullable', 'string'],
            'minutes_late' => ['nullable', 'integer'], 'recorded_at' => ['nullable'],
            'notes' => ['nullable', 'string'],
            'authorized' => ['sometimes', 'boolean'],
            'authorization_reason' => ['required_if:authorized,true', 'nullable', 'string', 'min:5', 'max:1000'],
        ]);

        $authorized = $this->authorizedOverride($request, $validated['authorized'] ?? false);
        $authorizationReason = $validated['authorization_reason'] ?? null;
        unset($validated['authorized'], $validated['authorization_reason']);

        return response()->json([
            'data' => $this->attendance->record($validated, $authorized, $authorizationReason),
        ], 201);
    }

    public function bulk(Request $request): JsonResponse
    {
        $request->user()->can('religious.attendance.record') || abort(403);
        $validated = $request->validate([
            'records' => ['required', 'array', 'min:1'],
            'records.*.prayer_type' => ['required', 'string'],
            'authorized' => ['sometimes', 'boolean'],
            'authorization_reason' => ['required_if:authorized,true', 'nullable', 'string', 'min:5', 'max:1000'],
        ]);

        $authorized = $this->authorizedOverride($request, $validated['authorized'] ?? false);
        $authorizationReason = $validated['authorization_reason'] ?? null;

        return response()->json(['data' => $this->attendance->recordBulk(
            $validated['records'], $authorized, $authorizationReason
        )], 201);
    }

    /** Periksa jendela presensi sebelum mencatat (Aturan 5). */
    public function window(Request $request): JsonResponse
    {
        $request->user()->can('religious.attendance.view') || abort(403);

        return response()->json(['data' => $this->attendance->windowCheck(
            $request->only(['prayer_type', 'mosque_id', 'campus_id', 'dormitory_id', 'grade_level', 'prayer_schedule_id']),
            $request->input('at'),
        )]);
    }

    public function policies(Request $request): JsonResponse
    {
        $request->user()->can('religious.attendance.view') || abort(403);

        return response()->json(['data' => [
            'policies' => PrayerAttendancePolicy::query()->where('active', true)->get(),
            'default' => config('religious.attendance.default_policy'),
        ]]);
    }

    public function storePolicy(Request $request): JsonResponse
    {
        $request->user()->can('religious.settings.manage') || abort(403);
        $policy = PrayerAttendancePolicy::query()->create($request->validate([
            'code' => ['required', 'string'], 'name' => ['required', 'string'],
            'scope_type' => ['nullable', 'string'], 'organization_id' => ['nullable', 'integer'],
            'campus_id' => ['nullable', 'integer'], 'dormitory_id' => ['nullable', 'integer'],
            'grade_level' => ['nullable', 'string'], 'program_id' => ['nullable', 'integer'],
            'prayer_types' => ['nullable', 'array'], 'grace_period_minutes' => ['nullable', 'integer'],
            'late_threshold_minutes' => ['nullable', 'integer'], 'window_before_minutes' => ['nullable', 'integer'],
            'window_after_minutes' => ['nullable', 'integer'], 'excused_rules' => ['nullable', 'array'],
            'weekend_rules' => ['nullable', 'array'], 'holiday_rules' => ['nullable', 'array'],
            'require_congregation' => ['nullable', 'boolean'], 'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date'], 'priority' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
        ]) + ['active' => true, 'status' => 'ACTIVE']);

        return response()->json(['data' => $policy], 201);
    }

    /** Aturan shalat berjamaah asrama (§24). */
    public function boardingRules(Request $request, int $studentId): JsonResponse
    {
        $request->user()->can('religious.attendance.view') || abort(403);

        return response()->json(['data' => $this->attendance->boardingRequirements(
            $studentId, (string) $request->input('date', now()->toDateString())
        )]);
    }

    public function storeBoardingRule(Request $request): JsonResponse
    {
        $request->user()->can('religious.settings.manage') || abort(403);

        return response()->json(['data' => $this->attendance->setBoardingRule($request->validate([
            'dormitory_id' => ['nullable', 'integer'], 'grade_level' => ['nullable', 'string'],
            'program_id' => ['nullable', 'integer'], 'prayer_types' => ['required', 'array'],
            'policy_id' => ['nullable', 'integer'], 'required' => ['nullable', 'boolean'],
            'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]))], 201);
    }

    // ── Penyesuaian (§95, Aturan 5) ─────────────────────────────────────

    public function requestAdjustment(Request $request): JsonResponse
    {
        $request->user()->can('religious.attendance.adjust') || abort(403);

        return response()->json(['data' => $this->attendance->requestAdjustment($request->validate([
            'attendance_id' => ['nullable', 'integer'], 'student_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'], 'prayer_type' => ['nullable', 'string'],
            'attendance_date' => ['nullable', 'date'], 'from_status' => ['nullable', 'string'],
            'to_status' => ['required', 'string'], 'reason' => ['required', 'string'],
            'evidence_document_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string'],
        ]))], 201);
    }

    public function approveAdjustment(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.attendance.approve') || abort(403);

        return response()->json(['data' => $this->attendance->approveAdjustment($id, (string) $request->input('note', ''))]);
    }

    // ── Statistik & penutupan ────────────────────────────────────────────────

    public function statistics(Request $request): JsonResponse
    {
        $request->user()->can('religious.attendance.view') || abort(403);

        return response()->json(['data' => $this->attendance->statistics(
            (string) $request->input('date', now()->toDateString()),
            $request->only(['campus_id', 'mosque_id', 'dormitory_id', 'grade_level', 'class_id', 'prayer_type', 'participant_type']),
        )]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $request->user()->can('religious.attendance.view') || abort(403);

        return response()->json(['data' => $this->attendance->dashboard(
            (string) $request->input('date', now()->toDateString()),
            $request->only(['campus_id', 'mosque_id', 'dormitory_id', 'grade_level', 'class_id', 'prayer_type']),
        )]);
    }

    /** Tutup presensi harian (§75). */
    public function close(Request $request): JsonResponse
    {
        $request->user()->can('religious.attendance.approve') || abort(403);
        $validated = $request->validate(['date' => ['required', 'date']]);

        return response()->json(['data' => $this->attendance->closeDay($validated['date'], $request->only([
            'campus_id', 'mosque_id', 'scope_type', 'scope_id', 'notes',
        ]))]);
    }

    /**
     * Teruskan fakta presensi ke kedisiplinan (§26).
     * Nonaktif secara bawaan — Aturan 12.
     */
    public function forwardDiscipline(Request $request): JsonResponse
    {
        $request->user()->can('religious.attendance.approve') || abort(403);
        $validated = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date']]);

        return response()->json(['data' => $this->attendance->forwardToDiscipline(
            $validated['from'], $validated['to'], $request->only(['student_id'])
        )]);
    }

    /**
     * Override jendela presensi tidak boleh ditentukan sepihak oleh payload.
     * Hanya petugas dengan izin persetujuan yang dapat melewatinya, dan alasannya
     * wajib dikirim agar tercatat pada audit log.
     */
    private function authorizedOverride(Request $request, mixed $requested): bool
    {
        if (! filter_var($requested, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $request->user()->can('religious.attendance.approve')
            || abort(403, 'Override jendela presensi memerlukan izin persetujuan.');

        return true;
    }
}
