<?php

namespace App\Services\Attendance;

use App\Models\AttendanceStatus;
use App\Models\ClassStudent;
use App\Models\EffectiveDay;
use App\Models\Semester;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentAttendance;
use Illuminate\Support\Collection;

/**
 * Rekap presensi.
 *
 * Persentase kehadiran dihitung terhadap **hari efektif** semester, bukan
 * jumlah hari kalender — kalau tidak, siswa yang tidak pernah alpa pun bisa
 * terlihat "tidak hadir" hanya karena libur semester ikut dihitung.
 *
 * Kalau semester itu belum punya data hari efektif sama sekali (Tahap A4 belum
 * dijalankan), penyebutnya jatuh ke jumlah tanggal presensi yang benar-benar
 * tercatat, dan hal itu ditandai lewat `basis`.
 */
class AttendanceRecapService
{
    /**
     * Jumlah hari efektif satu semester.
     *
     * @return int|null null bila semester itu belum punya data hari efektif
     */
    public function effectiveDayCount(int $semesterId, ?int $educationLevelId = null): ?int
    {
        $query = EffectiveDay::query()
            ->where('semester_id', $semesterId)
            ->where('is_school_day', true);

        if ($educationLevelId !== null) {
            $query->where(function ($q) use ($educationLevelId) {
                $q->whereNull('education_level_id')
                    ->orWhere('education_level_id', $educationLevelId);
            });
        }

        $count = $query->count();

        return $count > 0 ? $count : null;
    }

    /**
     * Rekap presensi satu siswa pada satu semester.
     *
     * @return array<string, mixed>
     */
    public function forStudent(int $studentId, int $semesterId, ?int $educationLevelId = null): array
    {
        $rows = StudentAttendance::query()
            ->with('attendanceStatus')
            ->forSemester($semesterId)
            ->where('student_id', $studentId)
            ->get();

        $counts = $this->countByStatusCode($rows);

        $effectiveDays = $this->effectiveDayCount($semesterId, $educationLevelId);
        $recordedDays = $rows->pluck('date')->filter()->unique()->count();

        $basis = $effectiveDays ?? $recordedDays;
        $present = $counts[AttendanceStatus::CODE_PRESENT] + $counts[AttendanceStatus::CODE_LATE];

        return [
            'student_id' => $studentId,
            'semester_id' => $semesterId,
            'effective_days' => $effectiveDays,
            'recorded_days' => $recordedDays,
            'basis' => $effectiveDays !== null ? 'effective_days' : 'recorded_days',
            'present' => $counts[AttendanceStatus::CODE_PRESENT],
            'late' => $counts[AttendanceStatus::CODE_LATE],
            'sick' => $counts[AttendanceStatus::CODE_SICK],
            'permitted' => $counts[AttendanceStatus::CODE_PERMITTED],
            'absent' => $counts[AttendanceStatus::CODE_ABSENT],
            'total_present' => $present,
            'percentage' => $basis > 0 ? round($present / $basis * 100, 2) : 0.0,
            'breakdown' => $counts,
        ];
    }

    /**
     * Rekap presensi seluruh siswa dalam satu kelas.
     *
     * @return array<string, mixed>
     */
    public function forClass(int $classId, int $semesterId): array
    {
        $students = Student::query()
            ->whereIn('id', ClassStudent::query()
                ->where('school_class_id', $classId)
                ->where('status', 'aktif')
                ->pluck('student_id'))
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'nis']);

        // Jenjang diambil dari kelas, bukan dari siswa (tabel `students` tidak
        // menyimpan jenjang; jenjang melekat pada kelas).
        $educationLevelId = SchoolClass::query()->whereKey($classId)->value('education_level_id');
        $educationLevelId = $educationLevelId !== null ? (int) $educationLevelId : null;

        $items = $students->map(function (Student $student) use ($semesterId, $educationLevelId) {
            $recap = $this->forStudent((int) $student->id, $semesterId, $educationLevelId);

            return array_merge($recap, [
                'student' => [
                    'id' => $student->id,
                    'nis' => $student->nis,
                    'full_name' => $student->full_name,
                ],
            ]);
        })->values();

        $effectiveDays = $this->effectiveDayCount($semesterId, $educationLevelId);

        return [
            'class_id' => $classId,
            'semester_id' => $semesterId,
            'education_level_id' => $educationLevelId,
            'effective_days' => $effectiveDays,
            'student_count' => $items->count(),
            'items' => $items,
            'totals' => [
                'present' => (int) $items->sum('present'),
                'late' => (int) $items->sum('late'),
                'sick' => (int) $items->sum('sick'),
                'permitted' => (int) $items->sum('permitted'),
                'absent' => (int) $items->sum('absent'),
                'average_percentage' => $items->count() > 0
                    ? round((float) $items->avg('percentage'), 2)
                    : 0.0,
            ],
        ];
    }

    /**
     * Semester aktif, dipakai sebagai nilai default saat tidak dikirim klien.
     */
    public function activeSemesterId(): ?int
    {
        return Semester::query()->where('is_active', true)->value('id');
    }

    /**
     * Hitung jumlah tiap kode status presensi.
     *
     * @param  Collection<int, StudentAttendance>  $rows
     * @return array<string, int>
     */
    protected function countByStatusCode(Collection $rows): array
    {
        $counts = [
            AttendanceStatus::CODE_PRESENT => 0,
            AttendanceStatus::CODE_ABSENT => 0,
            AttendanceStatus::CODE_PERMITTED => 0,
            AttendanceStatus::CODE_SICK => 0,
            AttendanceStatus::CODE_LATE => 0,
        ];

        foreach ($rows as $row) {
            $code = $row->attendanceStatus?->code;

            if ($code === null) {
                continue;
            }

            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }

        return $counts;
    }
}
