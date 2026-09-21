<?php

namespace Database\Seeders;

use App\Models\EventType;
use Illuminate\Database\Seeder;

/**
 * A4 — jenis event kalender akademik.
 *
 * `is_holiday` menandai jenis yang secara default meniadakan hari sekolah.
 */
class EventTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'AWAL_SEMESTER', 'name' => 'Awal Semester', 'color' => '#2563eb', 'is_holiday' => false],
            ['code' => 'AKHIR_SEMESTER', 'name' => 'Akhir Semester', 'color' => '#1d4ed8', 'is_holiday' => false],
            ['code' => 'UTS', 'name' => 'Ujian Tengah Semester', 'color' => '#f59e0b', 'is_holiday' => false],
            ['code' => 'UAS', 'name' => 'Ujian Akhir Semester', 'color' => '#d97706', 'is_holiday' => false],
            ['code' => 'LIBUR_NASIONAL', 'name' => 'Libur Nasional', 'color' => '#ef4444', 'is_holiday' => true],
            ['code' => 'LIBUR_SEMESTER', 'name' => 'Libur Semester', 'color' => '#dc2626', 'is_holiday' => true],
            ['code' => 'RAPAT_GURU', 'name' => 'Rapat Guru', 'color' => '#8b5cf6', 'is_holiday' => false],
            ['code' => 'PEMBAGIAN_RAPOR', 'name' => 'Pembagian Rapor', 'color' => '#10b981', 'is_holiday' => false],
            ['code' => 'KEGIATAN_SEKOLAH', 'name' => 'Kegiatan Sekolah', 'color' => '#0ea5e9', 'is_holiday' => false],
            ['code' => 'MPLS', 'name' => 'Masa Pengenalan Lingkungan Sekolah', 'color' => '#14b8a6', 'is_holiday' => false],
        ];

        foreach ($types as $type) {
            EventType::updateOrCreate(
                ['code' => $type['code']],
                $type + ['is_active' => true],
            );
        }
    }
}
