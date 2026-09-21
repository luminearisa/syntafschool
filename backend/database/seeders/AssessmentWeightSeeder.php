<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\AssessmentType;
use App\Models\AssessmentWeight;
use App\Models\SchoolClass;
use Illuminate\Database\Seeder;

/**
 * A7 — Bobot penilaian per kelas per tahun ajaran.
 *
 * Bobot disimpan per kombinasi kelas–tahun–jenis, bukan sebagai konstanta kode,
 * supaya tiap kelas boleh punya kebijakan berbeda. Nilai di bawah berjumlah
 * tepat 100 agar `GradingService::build()` melaporkan `weights_complete` true;
 * jenis `sikap` tidak ikut dibobot karena dilaporkan terpisah.
 */
class AssessmentWeightSeeder extends Seeder
{
    /**
     * Kode jenis → bobot (persen). Jumlahnya harus 100.
     *
     * @var array<string, float>
     */
    protected array $weights = [
        'tugas' => 20,
        'kuis' => 10,
        'praktik' => 15,
        'projek' => 15,
        'portofolio' => 5,
        'sts' => 20,
        'sas' => 15,
    ];

    public function run(): void
    {
        $year = AcademicYear::query()->where('is_active', true)->first()
            ?? AcademicYear::query()->first();

        if (! $year) {
            $this->command?->warn('AssessmentWeightSeeder dilewati: belum ada tahun ajaran.');

            return;
        }

        $classes = SchoolClass::query()->orderBy('id')->get();

        if ($classes->isEmpty()) {
            $this->command?->warn('AssessmentWeightSeeder dilewati: belum ada kelas.');

            return;
        }

        $typeIds = AssessmentType::query()->whereIn('code', array_keys($this->weights))
            ->pluck('id', 'code');

        if ($typeIds->isEmpty()) {
            $this->command?->warn('AssessmentWeightSeeder dilewati: jalankan AssessmentTypeSeeder lebih dulu.');

            return;
        }

        $rows = 0;

        foreach ($classes as $class) {
            foreach ($typeIds as $code => $typeId) {
                AssessmentWeight::updateOrCreate(
                    [
                        'school_class_id' => $class->id,
                        'academic_year_id' => $year->id,
                        'assessment_type_id' => $typeId,
                    ],
                    ['weight' => $this->weights[$code]],
                );

                $rows++;
            }
        }

        $this->command?->info("Bobot penilaian: {$rows} baris (".count($classes)." kelas × {$typeIds->count()} jenis).");
    }
}
