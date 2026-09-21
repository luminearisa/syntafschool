<?php

namespace Database\Seeders;

use App\Models\AttendanceStatus;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\Semester;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * A6 — Presensi pegawai contoh (8 guru + staf) untuk periode yang sama
 * dengan presensi siswa.
 */
class EmployeeAttendanceSeeder extends Seeder
{
    public function run(): void
    {
        $semester = Semester::query()->where('is_active', true)->first()
            ?? Semester::query()->first();

        if (! $semester) {
            $this->command?->warn('EmployeeAttendanceSeeder dilewati: belum ada semester.');

            return;
        }

        $statuses = AttendanceStatus::query()->get()->keyBy('code');

        if ($statuses->isEmpty()) {
            $this->command?->warn('EmployeeAttendanceSeeder dilewati: attendance_statuses kosong.');

            return;
        }

        $dates = $this->schoolDays($semester);
        $employees = Employee::query()->orderBy('id')->get();

        foreach ($employees as $index => $employee) {
            foreach ($dates as $dayIndex => $date) {
                $code = 'H';
                $checkIn = '06:45:00';
                $checkOut = '15:00:00';

                if (($index + $dayIndex) % 19 === 0) {
                    $code = 'A';
                    $checkIn = null;
                    $checkOut = null;
                } elseif (($index + $dayIndex) % 9 === 0) {
                    $code = 'T';
                    $checkIn = '07:35:00';
                } elseif (($index + $dayIndex) % 14 === 0) {
                    $code = 'I';
                    $checkIn = null;
                    $checkOut = null;
                }

                EmployeeAttendance::updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'date' => $date,
                    ],
                    [
                        'check_in' => $checkIn,
                        'check_out' => $checkOut,
                        'attendance_status_id' => $statuses[$code]->id,
                    ],
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    protected function schoolDays(Semester $semester): array
    {
        $end = CarbonImmutable::parse($semester->end_date?->toDateString() ?? now()->toDateString());
        $start = CarbonImmutable::parse($semester->start_date?->toDateString() ?? $end->subMonths(5));

        $days = [];
        $cursor = $end;

        while ($cursor->greaterThanOrEqualTo($start) && count($days) < 10) {
            if ($cursor->dayOfWeek !== 0) {
                $days[] = $cursor->toDateString();
            }
            $cursor = $cursor->subDay();
        }

        return array_reverse($days);
    }
}
