<?php

namespace App\Modules\Clinic\Infrastructure\Providers;

use App\Models\StudentAttendance;
use App\Modules\Clinic\Domain\Contracts\AttendanceProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * Presensi siswa dari kunjungan klinis.
 *
 * Kebijakan default: **tidak menulis** presensi (`clinic.attendance.auto_mark`
 * = false). Alasannya, presensi adalah data resmi kelas; klinik hanya boleh
 * melaporkan bahwa siswa berada di UKS. Bila sekolah mengaktifkan kebijakan
 * itu, baris presensi dibuat dengan status `recorded` (belum terverifikasi)
 * supaya wali kelas tetap dapat meninjau, dan tidak menimpa baris yang sudah
 * ada — hanya dilaporkan sebagai ALREADY_PRESENT.
 */
class EloquentAttendanceProvider implements AttendanceProviderInterface
{
    public function markMedicalVisit(int $studentId, string $date, string $reasonLabel, ?int $actorId): array
    {
        if (! (bool) config('clinic.attendance.auto_mark', false)) {
            return [
                'status' => self::SKIPPED_POLICY,
                'attendance_id' => null,
                'message' => 'Kebijakan sekolah tidak mengizinkan Clinic mengubah presensi; laporkan ke wali kelas.',
            ];
        }

        $semesterId = DB::table('semesters')->where('is_active', true)->value('id');
        if ($semesterId === null) {
            return [
                'status' => self::FAILED,
                'attendance_id' => null,
                'message' => 'Semester aktif belum ditetapkan, presensi tidak dapat ditulis.',
            ];
        }

        $classId = DB::table('class_students')
            ->where('student_id', $studentId)
            ->where('status', 'aktif')
            ->orderByDesc('id')
            ->value('school_class_id');

        if ($classId === null) {
            return [
                'status' => self::SKIPPED_NOT_STUDENT,
                'attendance_id' => null,
                'message' => 'Siswa tidak terdaftar pada kelas aktif mana pun.',
            ];
        }

        $existing = StudentAttendance::query()
            ->where('student_id', $studentId)
            ->where('school_class_id', $classId)
            ->whereDate('date', $date)
            ->first();

        if ($existing !== null) {
            return [
                'status' => self::ALREADY_PRESENT,
                'attendance_id' => (int) $existing->id,
                'message' => 'Presensi siswa pada tanggal tersebut sudah ada dan tidak diubah.',
            ];
        }

        $statusId = $this->resolveAttendanceStatusId($reasonLabel);

        if ($statusId === null) {
            return [
                'status' => self::FAILED,
                'attendance_id' => null,
                'message' => 'Master status presensi untuk alasan klinis belum tersedia.',
            ];
        }

        $attendance = StudentAttendance::query()->create([
            'student_id' => $studentId,
            'school_class_id' => $classId,
            'semester_id' => $semesterId,
            'date' => $date,
            'attendance_status_id' => $statusId,
            'notes' => 'Kunjungan UKS/Klinik: '.$reasonLabel,
            'status' => StudentAttendance::STATUS_RECORDED,
            'recorded_by' => $this->employeeIdFor($actorId),
        ]);

        return [
            'status' => self::MARKED,
            'attendance_id' => (int) $attendance->id,
            'message' => 'Presensi ditandai sebagai alasan klinis (menunggu verifikasi wali kelas).',
        ];
    }

    /**
     * Cari status presensi yang mewakili sakit/izin. Dicocokkan lewat nama
     * master sehingga sekolah bebas memberi nama sendiri.
     */
    private function resolveAttendanceStatusId(string $reasonLabel): ?int
    {
        $id = DB::table('attendance_statuses')
            ->where('name', 'like', '%sakit%')
            ->orWhere('name', 'like', '%izin%')
            ->orderByRaw("CASE WHEN name LIKE '%sakit%' THEN 0 ELSE 1 END")
            ->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        // Cadangan terakhir: buat status baru hanya bila tabel master kosong
        // sama sekali tidak punya kandidat, supaya penulisan presensi tetap sah.
        return null;
    }

    /** `student_attendances.recorded_by` menunjuk ke employees, bukan users. */
    private function employeeIdFor(?int $userId): ?int
    {
        if ($userId === null) {
            return null;
        }

        $employeeId = DB::table('users')->where('id', $userId)->value('userable_id');

        return $employeeId === null ? null : (int) $employeeId;
    }
}
