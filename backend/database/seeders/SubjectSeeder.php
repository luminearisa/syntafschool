<?php

namespace Database\Seeders;

use App\Models\Curriculum;
use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * Katalog mata pelajaran untuk jenjang SMA (Kurikulum Merdeka).
 *
 * Catatan desain: `education_level_id` sengaja dibiarkan null karena katalog
 * mapel berlaku lintas tingkat (Kelas 10–12). Pemetaan mapel ke tingkat
 * tertentu dilakukan oleh struktur kurikulum (tabel `curriculum_structures`),
 * bukan dengan menduplikasi mapel per tingkat.
 */
class SubjectSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name: string, category: string, credit: float}>
     */
    protected array $subjects = [
        // Kelompok Wajib
        ['code' => 'PABP', 'name' => 'Pendidikan Agama dan Budi Pekerti', 'category' => 'Wajib', 'credit' => 3],
        ['code' => 'PKN', 'name' => 'Pendidikan Pancasila dan Kewarganegaraan', 'category' => 'Wajib', 'credit' => 2],
        ['code' => 'BIND', 'name' => 'Bahasa Indonesia', 'category' => 'Wajib', 'credit' => 4],
        ['code' => 'MTK', 'name' => 'Matematika', 'category' => 'Wajib', 'credit' => 4],
        ['code' => 'BING', 'name' => 'Bahasa Inggris', 'category' => 'Wajib', 'credit' => 3],
        ['code' => 'SEJIND', 'name' => 'Sejarah Indonesia', 'category' => 'Wajib', 'credit' => 2],
        ['code' => 'PJOK', 'name' => 'Pendidikan Jasmani, Olahraga dan Kesehatan', 'category' => 'Wajib', 'credit' => 3],
        ['code' => 'SENBUD', 'name' => 'Seni Budaya', 'category' => 'Wajib', 'credit' => 2],
        ['code' => 'PKWU', 'name' => 'Prakarya dan Kewirausahaan', 'category' => 'Wajib', 'credit' => 2],
        ['code' => 'INF', 'name' => 'Informatika', 'category' => 'Wajib', 'credit' => 2],

        // Kelompok Pilihan — MIPA
        ['code' => 'FIS', 'name' => 'Fisika', 'category' => 'Pilihan', 'credit' => 3],
        ['code' => 'KIM', 'name' => 'Kimia', 'category' => 'Pilihan', 'credit' => 3],
        ['code' => 'BIO', 'name' => 'Biologi', 'category' => 'Pilihan', 'credit' => 3],
        ['code' => 'MTKL', 'name' => 'Matematika Lanjut', 'category' => 'Pilihan', 'credit' => 3],

        // Kelompok Pilihan — IPS
        ['code' => 'EKO', 'name' => 'Ekonomi', 'category' => 'Pilihan', 'credit' => 3],
        ['code' => 'GEO', 'name' => 'Geografi', 'category' => 'Pilihan', 'credit' => 3],
        ['code' => 'SOS', 'name' => 'Sosiologi', 'category' => 'Pilihan', 'credit' => 3],
        ['code' => 'SEJLAN', 'name' => 'Sejarah Lanjut', 'category' => 'Pilihan', 'credit' => 3],

        // Kelompok Pilihan — Bahasa
        ['code' => 'BINDLAN', 'name' => 'Bahasa Indonesia Lanjut', 'category' => 'Pilihan', 'credit' => 3],
        ['code' => 'BINGLAN', 'name' => 'Bahasa Inggris Lanjut', 'category' => 'Pilihan', 'credit' => 3],
        ['code' => 'BJEP', 'name' => 'Bahasa Jepang', 'category' => 'Pilihan', 'credit' => 2],

        // Muatan Lokal
        ['code' => 'BJWA', 'name' => 'Bahasa Jawa', 'category' => 'Muatan Lokal', 'credit' => 2],
        ['code' => 'PLH', 'name' => 'Pendidikan Lingkungan Hidup', 'category' => 'Muatan Lokal', 'credit' => 1],
    ];

    public function run(): void
    {
        $curriculum = Curriculum::where('is_active', true)->first()
            ?? Curriculum::where('code', 'MERDEKA')->first()
            ?? Curriculum::first();

        foreach ($this->subjects as $row) {
            Subject::firstOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'curriculum_id' => $curriculum?->id,
                    'education_level_id' => null,
                    'category' => $row['category'],
                    'credit' => $row['credit'],
                    'is_active' => true,
                ],
            );
        }
    }
}
