<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\AttendanceStatus;
use App\Models\Curriculum;
use App\Models\Day;
use App\Models\EducationLevel;
use App\Models\FiscalYear;
use App\Models\PaymentMethod;
use App\Models\Semester;
use Illuminate\Database\Seeder;

class AcademicSeeder extends Seeder
{
    public function run(): void
    {
        Curriculum::firstOrCreate(['code' => 'K13'], ['name' => 'Kurikulum 2013', 'is_active' => false]);
        Curriculum::firstOrCreate(['code' => 'MERDEKA'], ['name' => 'Kurikulum Merdeka', 'is_active' => true]);

        $jenjang = ['SD' => 6, 'SMP' => 3, 'SMA' => 3, 'SMK' => 3];
        foreach ($jenjang as $j => $max) {
            for ($i = 1; $i <= $max; $i++) {
                EducationLevel::firstOrCreate(
                    ['name' => "Kelas {$i}", 'jenjang' => $j],
                    ['level_number' => $i, 'is_active' => true]
                );
            }
        }

        $year = AcademicYear::firstOrCreate(
            ['name' => '2025/2026'],
            ['start_date' => '2025-07-01', 'end_date' => '2026-06-30', 'is_active' => true]
        );

        Semester::firstOrCreate(
            ['academic_year_id' => $year->id, 'name' => 'Ganjil'],
            ['start_date' => '2025-07-01', 'end_date' => '2025-12-31', 'is_active' => true]
        );
        Semester::firstOrCreate(
            ['academic_year_id' => $year->id, 'name' => 'Genap'],
            ['start_date' => '2026-01-01', 'end_date' => '2026-06-30', 'is_active' => false]
        );

        $days = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
        foreach ($days as $id => $name) {
            Day::updateOrCreate(['id' => $id], ['name' => $name]);
        }

        FiscalYear::firstOrCreate(
            ['name' => '2025/2026'],
            ['start_date' => '2025-07-01', 'end_date' => '2026-06-30', 'is_active' => true]
        );

        $methods = [
            ['code' => 'CASH', 'name' => 'Tunai'],
            ['code' => 'TRANSFER', 'name' => 'Transfer Bank'],
            ['code' => 'VA', 'name' => 'Virtual Account'],
            ['code' => 'QRIS', 'name' => 'QRIS'],
        ];
        foreach ($methods as $m) {
            PaymentMethod::firstOrCreate(['code' => $m['code']], array_merge($m, ['is_active' => true]));
        }

        $statuses = [
            ['code' => 'H', 'name' => 'Hadir', 'color' => '#22c55e'],
            ['code' => 'A', 'name' => 'Alpa', 'color' => '#ef4444'],
            ['code' => 'I', 'name' => 'Izin', 'color' => '#f59e0b'],
            ['code' => 'S', 'name' => 'Sakit', 'color' => '#3b82f6'],
            ['code' => 'T', 'name' => 'Terlambat', 'color' => '#eab308'],
        ];
        foreach ($statuses as $s) {
            AttendanceStatus::firstOrCreate(['code' => $s['code']], $s);
        }
    }
}
