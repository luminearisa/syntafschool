<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Employee;
use App\Models\SchoolClass;
use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * Mapel per kelas + guru pengampu.
 *
 * Tanpa seeder ini, `class_subjects` kosong pada database yang baru di-seed,
 * sehingga modul di atasnya (jurnal mengajar, tugas, penerapan kurikulum,
 * penilaian) tidak punya apa pun untuk diuji.
 *
 * Guru dibagi rata (round-robin) ke seluruh kelas, sehingga tiap guru punya
 * beban mengajar di lebih dari satu kelas.
 */
class ClassSubjectSeeder extends Seeder
{
    public function run(): void
    {
        $year = AcademicYear::query()->where('is_active', true)->first()
            ?? AcademicYear::query()->first();

        if (! $year) {
            $this->command?->warn('ClassSubjectSeeder dilewati: belum ada tahun ajaran.');

            return;
        }

        $classes = SchoolClass::query()->orderBy('id')->get();

        if ($classes->isEmpty()) {
            $this->command?->warn('ClassSubjectSeeder dilewati: belum ada kelas.');

            return;
        }

        $teachers = Employee::query()->where('is_teacher', true)->orderBy('id')->get();

        if ($teachers->isEmpty()) {
            $this->command?->warn('ClassSubjectSeeder dilewati: belum ada guru.');

            return;
        }

        // Mapel wajib untuk semua kelas.
        $subjects = Subject::query()->where('category', 'Wajib')->orderBy('id')->get();

        if ($subjects->isEmpty()) {
            $this->command?->warn('ClassSubjectSeeder dilewati: belum ada mapel wajib.');

            return;
        }

        $cursor = 0;

        foreach ($classes as $class) {
            foreach ($subjects as $subject) {
                $teacher = $teachers[$cursor % $teachers->count()];
                $cursor++;

                ClassSubject::updateOrCreate(
                    [
                        'school_class_id' => $class->id,
                        'subject_id' => $subject->id,
                    ],
                    [
                        'employee_id' => $teacher->id,
                        'academic_year_id' => $year->id,
                        'weekly_hours' => $subject->credit ?: 2,
                    ],
                );
            }
        }
    }
}
