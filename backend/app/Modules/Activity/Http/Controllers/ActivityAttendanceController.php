<?php

namespace App\Modules\Activity\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Activity\Application\Services\ActivityAttendanceService;
use App\Modules\Activity\Application\Services\ActivityAccessService;
use App\Modules\Activity\Models\ActivitySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Kehadiran aktivitas (\§33\–35).
 */
class ActivityAttendanceController extends BaseApiController
{
    public function __construct(
        private readonly ActivityAttendanceService $attendance,
        private readonly ActivityAccessService $access,
    ) {}

    /** Daftar hadir satu sesi (anggota aktif + catatan yang sudah ada). */
    public function sheet(ActivitySession $session, Request $request): JsonResponse
    {
        if (! $this->access->canRecordAttendance($request->user(), $session) && ! $request->user()->can('activity.attendance.view')) {
            return $this->errorResponse('Anda tidak berwenang melihat kehadiran sesi ini.', 403);
        }

        return $this->successResponse([
            'session' => $this->attendance->sessionSheet($session),
            'meta' => [
                'session_id' => (int) $session->id,
                'program_id' => (int) $session->program_id,
                'date' => $session->date?->toDateString(),
                'locked' => $session->attendance_locked_at !== null,
            ],
        ]);
    }

    public function record(ActivitySession $session, Request $request): JsonResponse
    {
        $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'status' => ['required', Rule::in(['PRESENT', 'ABSENT', 'EXCUSED', 'LATE', 'SICK', 'PERMISSION'])],
            'minutes_late' => ['nullable', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string'],
            'source' => ['nullable', 'string', 'in:MANUAL,QR,NFC,RFID,IMPORT'],
            'idempotency_key' => ['nullable', 'string', 'max:96'],
        ]);

        $record = $this->attendance->record(
            $session,
            (int) $request->input('student_id'),
            $request->all(),
            $request->user(),
        );

        return $this->successResponse($record, 'Kehadiran dicatat.');
    }

    public function recordBulk(ActivitySession $session, Request $request): JsonResponse
    {
        $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'rows.*.status' => ['required', Rule::in(['PRESENT', 'ABSENT', 'EXCUSED', 'LATE', 'SICK', 'PERMISSION'])],
            'idempotency_key' => ['nullable', 'string', 'max:96'],
        ]);

        return $this->successResponse(
            $this->attendance->recordBulk($session, $request->input('rows'), $request->user(), $request->input('idempotency_key')),
            'Kehadiran sesi dicatat.',
        );
    }

    public function complete(ActivitySession $session, Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->attendance->completeSession($session, $request->user()),
            'Sesi diselesaikan dan kehadiran dikunci.',
        );
    }

    public function programSummary(int $programId, Request $request): JsonResponse
    {
        if (! $request->user()->can('activity.attendance.view')) {
            return $this->errorResponse('Anda tidak berwenang melihat rekap kehadiran.', 403);
        }

        return $this->successResponse($this->attendance->programSummary($programId));
    }

    /** Kehadiran saya / anak saya. */
    public function studentSummary(int $studentId, int $programId, Request $request): JsonResponse
    {
        if (! $this->access->canViewStudent($request->user(), $studentId)) {
            return $this->errorResponse('Anda tidak berwenang melihat kehadiran siswa ini.', 403);
        }

        return $this->successResponse($this->attendance->studentSummary($studentId, $programId));
    }
}
