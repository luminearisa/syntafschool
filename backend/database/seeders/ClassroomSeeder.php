<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\EducationLevel;
use App\Models\Room;
use App\Models\School;
use App\Models\SchoolClass;
use Illuminate\Database\Seeder;

class ClassroomSeeder extends Seeder
{
    public function run(): void
    {
        $year = AcademicYear::where('is_active', true)->first()
            ?? AcademicYear::first();

        if (! $year) {
            return;
        }

        $school = School::first();
        $room = Room::first();

        // Definisikan kelas contoh berdasar tingkat (jenjang SMA).
        //
        // Catatan: nama pada `education_levels` adalah "Kelas 1..3" **per jenjang**,
        // jadi tingkat SMA tidak dicari dengan nama "Kelas 10/11/12" melainkan
        // lewat pasangan `jenjang = SMA` + `level_number` (1 = kelas X, 2 = XI, 3 = XII).
        $definitions = [
            ['name' => 'X IPA 1', 'code' => 'X-IPA-1', 'level_number' => 1],
            ['name' => 'X IPA 2', 'code' => 'X-IPA-2', 'level_number' => 1],
            ['name' => 'XI IPA 1', 'code' => 'XI-IPA-1', 'level_number' => 2],
            ['name' => 'XII IPA 1', 'code' => 'XII-IPA-1', 'level_number' => 3],
        ];

        foreach ($definitions as $def) {
            $level = EducationLevel::where('jenjang', 'SMA')
                ->where('level_number', $def['level_number'])
                ->first();

            // updateOrCreate (bukan firstOrCreate) supaya kelas yang sudah ada
            // ikut diperbaiki bila `education_level_id` sebelumnya kosong.
            SchoolClass::updateOrCreate(
                ['code' => $def['code']],
                [
                    'school_id' => $school?->id,
                    'academic_year_id' => $year->id,
                    'education_level_id' => $level?->id,
                    'room_id' => $room?->id,
                    'name' => $def['name'],
                    'capacity' => 32,
                    'is_active' => true,
                ],
            );
        }
    }
}
