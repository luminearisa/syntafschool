<?php

namespace App\Modules\Activity\Infrastructure\Providers;

use App\Models\StudentAttendance;
use App\Modules\Activity\Domain\Contracts\AttendanceProviderInterface;
use App\Modules\Activity\Domain\Enums\AttendanceStatus;

/**
 * Cermin kehadiran aktivitas ke modul Attendance.
 *
 * Catatan kehadiran aktivitas tetap milik modul Activity (satu baris per sesi
 * per siswa). Kontrak ini hanya membuat rekaman pendamping di `student_attendances`
 * bila sekolah mengaktifkannya (`activity.attendance.mirror`), sehingga absensi
 * aktivitas pun terlihat di layar kehadiran yang sudah dikenal guru — tanpa
 * modul ini menyentuh tabel absensi akademik secara langsung.
 */
class EloquentAttendanceProvider implements AttendanceProviderInterface
{
    public function mirror(array $payload): ?string
    {
        if (! config('activity.attendance.mirror', false)) {
            return null;
        }

        $classId = $payload['class_id'] ?? null;
        $semesterId = $payload['semester_id'] ?? \App\Models\Semester::query()->where('is_active', true)->value('id');
        $statusId = $this->statusIdFor($payload['status'] ?? null);

        // Tabel absensi akademik mensyaratkan kelas, semester, dan status. Bila
        // salah satunya belum ada, catatan cerminan **tidak** dibuat — lebih
        // baik tidak ada cerminan daripada cerminan yang salah kelas.
        if ($classId === null || $semesterId === null || $statusId === null) {
            return null;
        }

        $attendance = StudentAttendance::query()->create([
            'student_id' => $payload['student_id'],
            'school_class_id' => $classId,
            'semester_id' => $semesterId,
            'date' => $payload['date'],
            'attendance_status_id' => $statusId,
            'time_in' => $payload['time_in'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'recorded_by' => $payload['recorded_by'] ?? null,
            'status' => 'activity',
        ]);

        return 'attendance:'.$attendance->id;
    }

    public function revoke(string $reference): bool
    {
        if (! str_starts_with($reference, 'attendance:')) {
            return false;
        }

        $id = (int) substr($reference, strlen('attendance:'));

        return (bool) StudentAttendance::query()->whereKey($id)->delete();
    }

    public function summary(int $studentId, string $from, string $to): array
    {
        $rows = StudentAttendance::query()
            ->where('student_id', $studentId)
            ->whereBetween('date', [$from, $to])
            ->with('attendanceStatus:id,name')
            ->get();

        $total = $rows->count();
        $present = $rows->filter(fn (StudentAttendance $row) => str_contains(
            strtolower((string) $row->attendanceStatus?->name),
            'hadir',
        ))->count();

        return [
            'total' => $total,
            'present' => $present,
            'percentage' => $total === 0 ? null : round($present / $total * 100, 2),
            'source' => 'academic',
        ];
    }

    protected function statusIdFor(?string $status): ?int
    {
        $needle = match ($status) {
            AttendanceStatus::PRESENT->value => 'hadir',
            AttendanceStatus::LATE->value => 'terlambat',
            AttendanceStatus::SICK->value => 'sakit',
            AttendanceStatus::EXCUSED->value, AttendanceStatus::PERMISSION->value => 'izin',
            AttendanceStatus::ABSENT->value => 'alpa',
            default => null,
        };

        if ($needle === null) {
            return null;
        }

        return \Illuminate\Support\Facades\DB::table('attendance_statuses')
            ->where('name', 'like', '%'.$needle.'%')
            ->value('id');
    }
}
