<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Attendance\AttendanceCorrectionRequest;
use App\Http\Requests\Attendance\RejectCorrectionRequest;
use App\Http\Resources\AttendanceCorrectionResource;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceStatus;
use App\Models\StudentAttendance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Koreksi presensi.
 *
 * Presensi yang sudah tercatat tidak diubah langsung. Guru mengajukan koreksi,
 * pemegang `academic.approve` yang memutuskan, dan status lama tersimpan di
 * baris koreksi sehingga perubahan tetap bisa diaudit.
 */
class AttendanceCorrectionController extends BaseApiController
{
    /** Kolom yang boleh difilter lewat query string. */
    protected array $filterable = ['status', 'student_attendance_id'];

    protected array $with = ['attendance.student', 'oldStatus', 'newStatus', 'leaveType', 'requester', 'approver'];

    public function index(Request $request): JsonResponse
    {
        $query = AttendanceCorrection::query()->with($this->with);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '' || ! in_array($column, $this->filterable, true)) {
                continue;
            }
            $query->where($column, $value);
        }

        if ($studentId = $request->query('student_id')) {
            $query->whereHas('attendance', fn ($q) => $q->where('student_id', $studentId));
        }

        $query->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                AttendanceCorrectionResource::collection($query->get()),
                'Daftar koreksi presensi berhasil diambil',
            );
        }

        return $this->successResponse(
            AttendanceCorrectionResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar koreksi presensi berhasil diambil',
        );
    }

    public function show(AttendanceCorrection $correction): JsonResponse
    {
        return $this->successResponse(
            new AttendanceCorrectionResource($correction->load($this->with)),
            'Detail koreksi presensi berhasil diambil',
        );
    }

    public function store(AttendanceCorrectionRequest $request): JsonResponse
    {
        $data = $request->validated();

        $attendance = StudentAttendance::findOrFail($data['student_attendance_id']);

        if ($attendance->attendance_status_id === (int) $data['new_status_id']) {
            throw ValidationException::withMessages([
                'new_status_id' => 'Status baru sama dengan status presensi saat ini.',
            ]);
        }

        $hasPending = AttendanceCorrection::query()
            ->where('student_attendance_id', $attendance->id)
            ->pending()
            ->exists();

        if ($hasPending) {
            throw ValidationException::withMessages([
                'student_attendance_id' => 'Presensi ini masih punya pengajuan koreksi yang belum diproses.',
            ]);
        }

        // Status Sakit/Izin wajib membawa jenis izin — sama seperti jalur presensi biasa.
        $newStatus = AttendanceStatus::find((int) $data['new_status_id']);

        if ($newStatus && in_array($newStatus->code, AttendanceStatus::CODES_REQUIRING_LEAVE_TYPE, true)
            && empty($data['leave_type_id'])) {
            throw ValidationException::withMessages([
                'leave_type_id' => 'Jenis izin wajib dipilih untuk status sakit/izin.',
            ]);
        }

        $correction = AttendanceCorrection::create([
            'student_attendance_id' => $attendance->id,
            'old_status_id' => $attendance->attendance_status_id,
            'new_status_id' => $data['new_status_id'],
            'leave_type_id' => $data['leave_type_id'] ?? null,
            'reason' => $data['reason'],
            'requested_by' => $request->user()?->id,
            'status' => AttendanceCorrection::STATUS_PENDING,
        ]);

        return $this->createdResponse(
            new AttendanceCorrectionResource($correction->load($this->with)),
            'Pengajuan koreksi presensi berhasil dikirim',
        );
    }

    /**
     * Setujui koreksi dan terapkan status baru ke presensi.
     */
    public function approve(Request $request, AttendanceCorrection $correction): JsonResponse
    {
        $this->assertPending($correction);

        $correction->update([
            'status' => AttendanceCorrection::STATUS_APPROVED,
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ]);

        $attendance = $correction->attendance;

        if ($attendance) {
            $attendance->update([
                'attendance_status_id' => $correction->new_status_id,
                'leave_type_id' => $correction->leave_type_id,
                'verified_by' => $request->user()?->id,
                'verified_at' => now(),
                'status' => StudentAttendance::STATUS_VERIFIED,
            ]);
        }

        return $this->successResponse(
            new AttendanceCorrectionResource($correction->refresh()->load($this->with)),
            'Koreksi presensi disetujui dan status presensi diperbarui',
        );
    }

    /**
     * Tolak koreksi — presensi tidak berubah.
     */
    public function reject(RejectCorrectionRequest $request, AttendanceCorrection $correction): JsonResponse
    {
        $this->assertPending($correction);

        $data = $request->validated();

        $correction->update([
            'status' => AttendanceCorrection::STATUS_REJECTED,
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
            'reason' => trim($correction->reason."\n\nDitolak: ".$data['reason']),
        ]);

        return $this->successResponse(
            new AttendanceCorrectionResource($correction->refresh()->load($this->with)),
            'Koreksi presensi ditolak',
        );
    }

    protected function assertPending(AttendanceCorrection $correction): void
    {
        if (! $correction->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'Koreksi ini sudah diproses sebelumnya.',
            ]);
        }
    }
}
