<?php

namespace App\Modules\Supervisi\Database\Seeders;

use App\Modules\Supervisi\Models\SupGradeScale;
use Illuminate\Database\Seeder;

/**
 * Skala predikat bawaan modul supervisi.
 *
 * 1. Ambang supervisi Kemendikbud (A/B/C/K 90/80/70) — bawaan.
 * 2. Sebutan PKG beserta persentase angka kredit (125/100/75/50/25).
 * 3. Skala HR 1–5 untuk tinjauan kinerja pegawai.
 */
class SupGradeScaleSeeder extends Seeder
{
    public function run(): void
    {
        $scales = [
            [
                'code' => 'kemendikbud_90_80_70',
                'name' => 'Kriteria Supervisi (A/B/C/K)',
                'scale_type' => 'PERCENT',
                'is_default' => true,
                'description' => 'Amat baik 90–100, Baik 80–90, Cukup 70–80, Kurang ≤ 70.',
                'bands' => [
                    ['code' => 'A', 'min' => 90, 'max' => 100, 'label' => 'Amat Baik', 'tone' => 'GREEN', 'credit_percent' => 125, 'min_inclusive' => true],
                    ['code' => 'B', 'min' => 80, 'max' => 90, 'label' => 'Baik', 'tone' => 'GREEN', 'credit_percent' => 100, 'min_inclusive' => true],
                    ['code' => 'C', 'min' => 70, 'max' => 80, 'label' => 'Cukup', 'tone' => 'AMBER', 'credit_percent' => 75, 'min_inclusive' => true],
                    ['code' => 'K', 'min' => 0, 'max' => 70, 'label' => 'Kurang', 'tone' => 'RED', 'credit_percent' => 50, 'min_inclusive' => true],
                ],
            ],
            [
                'code' => 'pkg_sebutan',
                'name' => 'Sebutan PKG & Angka Kredit',
                'scale_type' => 'PERCENT',
                'is_default' => false,
                'description' => 'Amat baik 91–100 (125%), Baik 76–90 (100%), Cukup 61–75 (75%), Sedang 51–60 (50%), Kurang ≤ 50 (25%).',
                'bands' => [
                    ['code' => 'AB', 'min' => 91, 'max' => 100, 'label' => 'Amat Baik', 'tone' => 'GREEN', 'credit_percent' => 125, 'min_inclusive' => true],
                    ['code' => 'B', 'min' => 76, 'max' => 91, 'label' => 'Baik', 'tone' => 'GREEN', 'credit_percent' => 100, 'min_inclusive' => true],
                    ['code' => 'C', 'min' => 61, 'max' => 76, 'label' => 'Cukup', 'tone' => 'AMBER', 'credit_percent' => 75, 'min_inclusive' => true],
                    ['code' => 'S', 'min' => 51, 'max' => 61, 'label' => 'Sedang', 'tone' => 'AMBER', 'credit_percent' => 50, 'min_inclusive' => true],
                    ['code' => 'K', 'min' => 0, 'max' => 51, 'label' => 'Kurang', 'tone' => 'RED', 'credit_percent' => 25, 'min_inclusive' => true],
                ],
            ],
            [
                'code' => 'hr_1_5',
                'name' => 'Tinjauan Kinerja Pegawai (Rata-rata Berbobot 1–5)',
                'scale_type' => 'PERCENT',
                'is_default' => false,
                'description' => 'Penilaian tiap kriteria memakai skala 1–5; nilai akhir berupa persentase berbobot. Istimewa ≥ 90; Melebihi harapan 80–90; Sesuai harapan 70–80; Perlu perbaikan 60–70; Tidak memenuhi < 60.',
                'bands' => [
                    ['code' => 'ISTIMEWA', 'min' => 90, 'max' => 100, 'label' => 'Istimewa', 'tone' => 'GREEN', 'min_inclusive' => true],
                    ['code' => 'MELEBIHI', 'min' => 80, 'max' => 90, 'label' => 'Melebihi Harapan', 'tone' => 'GREEN', 'min_inclusive' => true],
                    ['code' => 'SESUAI', 'min' => 70, 'max' => 80, 'label' => 'Sesuai Harapan', 'tone' => 'AMBER', 'min_inclusive' => true],
                    ['code' => 'PERLU_PERBAIKAN', 'min' => 60, 'max' => 70, 'label' => 'Perlu Perbaikan', 'tone' => 'RED', 'min_inclusive' => true],
                    ['code' => 'TIDAK_MEMENUHI', 'min' => 0, 'max' => 60, 'label' => 'Tidak Memenuhi', 'tone' => 'RED', 'min_inclusive' => true],
                ],
            ],
            [
                'code' => 'yes_no',
                'name' => 'Daftar Periksa (Ya/Tidak)',
                'scale_type' => 'PERCENT',
                'is_default' => false,
                'description' => 'Untuk instrumen berupa daftar periksa; hanya ada/terpenuhi vs tidak.',
                'bands' => [
                    ['code' => 'LENGKAP', 'min' => 90, 'max' => 100, 'label' => 'Lengkap', 'tone' => 'GREEN', 'min_inclusive' => true],
                    ['code' => 'SEBAGIAN', 'min' => 75, 'max' => 90, 'label' => 'Sebagian Besar Lengkap', 'tone' => 'AMBER', 'min_inclusive' => true],
                    ['code' => 'BELUM_LENGKAP', 'min' => 0, 'max' => 75, 'label' => 'Belum Lengkap', 'tone' => 'RED', 'min_inclusive' => true],
                ],
            ],
        ];

        foreach ($scales as $scale) {
            SupGradeScale::query()->updateOrCreate(
                ['code' => $scale['code']],
                [...$scale, 'is_system' => true],
            );
        }
    }
}
