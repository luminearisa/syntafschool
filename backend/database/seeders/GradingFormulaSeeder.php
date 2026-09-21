<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\GradingFormula;
use Illuminate\Database\Seeder;

/**
 * A7 — Formula perhitungan nilai akhir (berversi).
 *
 * Formula aktif dengan versi tertinggi menjadi default bila sebuah penilaian
 * tidak menentukan `grading_formula_id` sendiri. Varian `cap` disediakan tetapi
 * dinonaktifkan: sekolah boleh memakainya kalau nilai remedial tidak boleh
 * melebihi batas tertentu.
 */
class GradingFormulaSeeder extends Seeder
{
    public function run(): void
    {
        $year = AcademicYear::query()->where('is_active', true)->first()
            ?? AcademicYear::query()->first();

        $rows = [
            [
                'code' => 'RATA-BOBOT',
                'name' => 'Rata-rata Terbobot',
                'formula_type' => GradingFormula::TYPE_WEIGHTED_AVERAGE,
                'version' => 1,
                'is_active' => true,
                'settings' => [
                    'decimals' => 2,
                    'rounding' => 'half_up',
                    'remedial_rule' => GradingFormula::REMEDIAL_MAX,
                    'remedial_cap' => null,
                    'drop_lowest' => 0,
                ],
            ],
            [
                'code' => 'RATA-BOBOT-CAP',
                'name' => 'Rata-rata Terbobot (Remedial Dibatasi)',
                'formula_type' => GradingFormula::TYPE_WEIGHTED_AVERAGE,
                'version' => 2,
                'is_active' => false,
                'settings' => [
                    'decimals' => 2,
                    'rounding' => 'half_up',
                    'remedial_rule' => GradingFormula::REMEDIAL_CAP,
                    'remedial_cap' => 85,
                    'drop_lowest' => 0,
                ],
            ],
        ];

        foreach ($rows as $row) {
            GradingFormula::updateOrCreate(
                ['code' => $row['code']],
                $row + ['effective_academic_year_id' => $year?->id],
            );
        }

        $this->command?->info('Formula penilaian: '.count($rows).' varian siap (default: RATA-BOBOT).');
    }
}
