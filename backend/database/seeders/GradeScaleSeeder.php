<?php

namespace Database\Seeders;

use App\Models\GradeScale;
use App\Models\GradeScaleItem;
use Illuminate\Database\Seeder;

/**
 * A7 — Skala konversi nilai angka ke huruf + predikat.
 *
 * Satu skala umum (tanpa jenjang) dipakai semua kelas. Skala khusus per jenjang
 * boleh ditambahkan kemudian oleh sekolah; `GradeScale::resolveFor()` memilih
 * yang paling spesifik lalu jatuh ke yang umum.
 *
 * Rentang dibuat tidak bersentuhan (59.99 → 60) karena `GradeScaleController`
 * menolak rentang yang tumpang tindih, dan konversi memakai batas inklusif.
 */
class GradeScaleSeeder extends Seeder
{
    /**
     * @var list<array{grade: string, min: float, max: float, predicate: string, description: string}>
     */
    protected array $items = [
        ['grade' => 'A', 'min' => 90, 'max' => 100, 'predicate' => 'Sangat Baik', 'description' => 'Capaian penuh, siswa mampu menerapkan materi pada kasus baru.'],
        ['grade' => 'B', 'min' => 80, 'max' => 89.99, 'predicate' => 'Baik', 'description' => 'Capaian baik dengan sedikit kesalahan konsep.'],
        ['grade' => 'C', 'min' => 70, 'max' => 79.99, 'predicate' => 'Cukup', 'description' => 'Capaian mencapai batas minimal yang ditargetkan.'],
        ['grade' => 'D', 'min' => 60, 'max' => 69.99, 'predicate' => 'Kurang', 'description' => 'Perlu pendampingan tambahan pada beberapa kompetensi.'],
        ['grade' => 'E', 'min' => 0, 'max' => 59.99, 'predicate' => 'Sangat Kurang', 'description' => 'Belum mencapai kompetensi dasar, wajib mengikuti remedial.'],
    ];

    public function run(): void
    {
        $scale = GradeScale::updateOrCreate(
            ['code' => 'A-E'],
            [
                'curriculum_id' => null,
                'education_level_id' => null,
                'name' => 'Skala Huruf A–E',
                'description' => 'Konversi nilai 0–100 menjadi huruf dan predikat yang dipakai rapor.',
                'is_active' => true,
            ],
        );

        $keep = [];

        foreach ($this->items as $item) {
            $row = GradeScaleItem::updateOrCreate(
                ['grade_scale_id' => $scale->id, 'grade' => $item['grade']],
                [
                    'min_score' => $item['min'],
                    'max_score' => $item['max'],
                    'predicate' => $item['predicate'],
                    'description' => $item['description'],
                ],
            );

            $keep[] = $row->id;
        }

        // Bersihkan rentang sisa dari versi seed sebelumnya.
        GradeScaleItem::query()
            ->where('grade_scale_id', $scale->id)
            ->whereNotIn('id', $keep)
            ->delete();

        $this->command?->info('Skala nilai: 1 skala dengan '.count($this->items).' rentang huruf siap.');
    }
}
