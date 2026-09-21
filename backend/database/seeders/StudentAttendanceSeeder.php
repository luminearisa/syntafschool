<?php

namespace Database\Seeders;

use App\Models\AttendanceStatus;
use App\Models\ClassStudent;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * A6 — Presensi siswa contoh.
 *
 * Mengisi presensi ~10 hari sekolah terakhir pada semester aktif agar modul
 * rekap dan rapor punya data nyata untuk diuji. Sebagian besar hadir, dengan
 * sedikit sakit/izin/alpa/terlambat supaya persentase kehadiran tidak seragam.
 */
class StudentAttendanceSeeder extends Seeder
{
    public function run(): void
    {
        $semester = Semester::query()->where('is_active', true)->first()
            ?? Semester::query()->first();

        if (! $semester) {
            $this->command?->warn('StudentAttendanceSeeder dilewati: belum ada semester.');

            return;
        }

        $statuses = AttendanceStatus::query()->get()->keyBy('code');

        if ($statuses->isEmpty()) {
            $this->command?->warn('StudentAttendanceSeeder dilewati: attendance_statuses kosong.');

            return;
        }

        $leaveTypes = \App\Models\LeaveType::query()->get();
        $sickLeaveId = $leaveTypes->firstWhere('name', 'Sakit')?->id;
        $permitLeaveId = $leaveTypes->firstWhere('name', 'Izin')?->id;

        $dates = $this->schoolDays($semester);

        $classes = SchoolClass::query()->pluck('id');

        foreach ($classes as $classId) {
            $students = Student::query()
                ->whereIn('id', ClassStudent::query()
                    ->where('school_class_id', $classId)
                    ->where('status', 'aktif')
                    ->pluck('student_id'))
                ->orderBy('id')
                ->get();

            foreach ($students as $index => $student) {
                foreach ($dates as $dayIndex => $date) {
                    // Pola deterministik: tiap siswa punya "hari tidak hadir"
                    // yang berbeda, supaya rekapnya tidak seragam.
                    $code = 'H';
                    $leaveTypeId = null;

                    if (($index + $dayIndex) % 17 === 0) {
                        $code = 'A';
                    } elseif (($index + $dayIndex) % 13 === 0) {
                        $code = 'S';
                        $leaveTypeId = $sickLeaveId;
                    } elseif (($index + $dayIndex) % 11 === 0) {
                        $code = 'I';
                        $leaveTypeId = $permitLeaveId;
                    } elseif (($index + $dayIndex) % 7 === 0) {
                        $code = 'T';
                    }

                    StudentAttendance::updateOrCreate(
                        [
                            'student_id' => $student->id,
                            'date' => $date,
                            'schedule_id' => null,
                        ],
                        [
                            'school_class_id' => $classId,
                            'semester_id' => $semester->id,
                            'attendance_status_id' => $statuses[$code]->id,
                            'leave_type_id' => $leaveTypeId,
                            'time_in' => $code === 'T' ? '07:20:00' : ($code === 'H' ? '06:55:00' : null),
                            'status' => StudentAttendance::STATUS_RECORDED,
                        ],
                    );
                }
            }
        }
    }

    /**
     * Sepuluh hari sekolah terakhir (Senin–Sabtu) di dalam rentang semester.
     *
     * @return list<string>
     */
    protected function schoolDays(Semester $semester): array
    {
        $end = CarbonImmutable::parse($semester->end_date?->toDateString() ?? now()->toDateString());
        $start = CarbonImmutable::parse($semester->start_date?->toDateString() ?? $end->subMonths(5));

        $days = [];
        $cursor = $end;

        while ($cursor->greaterThanOrEqualTo($start) && count($days) < 10) {
            if ($cursor->dayOfWeek !== 0) { // 0 = Minggu
                $days[] = $cursor->toDateString();
            }
            $cursor = $cursor->subDay();
        }

        return array_reverse($days);
    }
}
