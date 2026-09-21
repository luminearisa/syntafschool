<?php

namespace Database\Seeders;

use App\Models\AssessmentType;
use Illuminate\Database\Seeder;

/**
 * A7 — Katalog jenis penilaian.
 *
 * Jenis penilaian adalah data, bukan skema: satu tabel `assessments` dipakai
 * semua jenis. Yang membedakan hanya nama, kategori, dan bobotnya. Bobot tidak
 * ikut di sini karena sifatnya per kelas per tahun ajaran
 * (lihat {@see AssessmentWeightSeeder}).
 *
 * `sikap` sengaja tidak diberi bobot: penilaian sikap dilaporkan sebagai
 * predikat tersendiri, bukan dicampur ke rata-rata pengetahuan/keterampilan.
 */
class AssessmentTypeSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name: string, category: string, required: bool, order: int}>
     */
    protected array $types = [
        ['code' => 'tugas', 'name' => 'Tugas Harian', 'category' => 'pengetahuan', 'required' => true, 'order' => 10],
        ['code' => 'kuis', 'name' => 'Kuis', 'category' => 'pengetahuan', 'required' => false, 'order' => 20],
        ['code' => 'praktik', 'name' => 'Penilaian Praktik', 'category' => 'keterampilan', 'required' => false, 'order' => 30],
        ['code' => 'projek', 'name' => 'Projek', 'category' => 'keterampilan', 'required' => false, 'order' => 40],
        ['code' => 'portofolio', 'name' => 'Portofolio', 'category' => 'keterampilan', 'required' => false, 'order' => 50],
        ['code' => 'sts', 'name' => 'Sumatif Tengah Semester', 'category' => 'pengetahuan', 'required' => true, 'order' => 60],
        ['code' => 'sas', 'name' => 'Sumatif Akhir Semester', 'category' => 'pengetahuan', 'required' => true, 'order' => 70],
        ['code' => 'sikap', 'name' => 'Penilaian Sikap', 'category' => 'sikap', 'required' => true, 'order' => 80],
    ];

    public function run(): void
    {
        $created = 0;

        foreach ($this->types as $type) {
            AssessmentType::updateOrCreate(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'category' => $type['category'],
                    'is_required' => $type['required'],
                    'sort_order' => $type['order'],
                    'is_active' => true,
                ],
            );

            $created++;
        }

        $this->command?->info("Jenis penilaian: {$created} baris siap.");
    }
}
