<?php

namespace App\Modules\Bk\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Bk\Application\Services\BkAppointmentService;
use App\Modules\Bk\Domain\Enums\AttendanceStatus;
use App\Modules\Bk\Models\BkAppointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Janji temu BK: daftar, pembuatan (dengan pemeriksaan bentrok), ubah jadwal,
 * pembatalan, kehadiran, dan pratinjau bentrok.
 *
 * Konselor melihat jadwalnya; koordinator melihat semua; siswa/wali melihat
 * janji miliknya sendiri — tanpa tujuan sesi yang bersifat konseling.
 */
class BkAppointmentController extends BaseApiController
{
    public function __construct(private readonly BkAppointmentService $appointments)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->appointments->paginate($request->user(), $request->only([
                'status', 'counselor_id', 'student_id', 'case_id', 'date', 'upcoming', 'from', 'to',
            ]), (int) $request->integer('per_page', 20)),
            'Daftar janji temu.',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'counselor_id' => ['nullable', 'integer', 'exists:users,id'],
            'case_id' => ['nullable', 'integer', 'exists:bk_cases,id'],
            'session_type' => ['nullable', 'string'],
            'mode' => ['nullable', 'string'],
            'scheduled_start' => ['required', 'date'],
            'scheduled_end' => ['required', 'date', 'after:scheduled_start'],
            'location' => ['nullable', 'string', 'max:120'],
            'room_id' => ['nullable', 'integer'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'recurrence_rule' => ['nullable', 'string', 'max:60'],
            'force' => ['nullable', 'boolean'],
        ]);

        return $this->createdResponse($this->appointments->create($request->user(), $validated), 'Janji temu dibuat.');
    }

    public function checkConflicts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'counselor_id' => ['required', 'integer', 'exists:users,id'],
            'scheduled_start' => ['required', 'date'],
            'scheduled_end' => ['required', 'date', 'after:scheduled_start'],
        ]);

        return $this->successResponse(
            $this->appointments->detectConflicts(
                (int) $validated['student_id'],
                (int) $validated['counselor_id'],
                $validated['scheduled_start'],
                $validated['scheduled_end'],
            ),
            'Pratinjau bentrok jadwal.',
        );
    }

    public function reschedule(Request $request, BkAppointment $appointment): JsonResponse
    {
        $this->authorize('manage', $appointment);

        $validated = $request->validate([
            'scheduled_start' => ['required', 'date'],
            'scheduled_end' => ['required', 'date', 'after:scheduled_start'],
            'reason' => ['nullable', 'string', 'max:191'],
        ]);

        return $this->successResponse(
            $this->appointments->reschedule($request->user(), $appointment, $validated['scheduled_start'], $validated['scheduled_end'], $validated['reason'] ?? null),
            'Janji temu dijadwalkan ulang.',
        );
    }

    public function cancel(Request $request, BkAppointment $appointment): JsonResponse
    {
        $this->authorize('cancel', $appointment);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:191']]);

        return $this->successResponse($this->appointments->cancel($request->user(), $appointment, $validated['reason']), 'Janji temu dibatalkan.');
    }

    public function attendance(Request $request, BkAppointment $appointment): JsonResponse
    {
        $this->authorize('manage', $appointment);

        $validated = $request->validate([
            'attendance_status' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:191'],
        ]);

        return $this->successResponse(
            $this->appointments->recordAttendance($request->user(), $appointment, AttendanceStatus::from($validated['attendance_status']), $validated['note'] ?? null),
            'Kehadiran janji temu dicatat.',
        );
    }
}
