<?php

namespace Database\Seeders;

use App\Models\AchievementType;
use Illuminate\Database\Seeder;

/**
 * Jenis prestasi siswa beserta tingkat default-nya.
 */
class AchievementTypeSeeder extends Seeder
{
    /**
     * @var list<array{name: string, level: string}>
     */
    protected array $types = [
        ['name' => 'Olimpiade Sains (OSN)', 'level' => 'Nasional'],
        ['name' => 'Olimpiade Olahraga Siswa Nasional', 'level' => 'Nasional'],
        ['name' => 'Festival Lomba Seni Siswa Nasional', 'level' => 'Nasional'],
        ['name' => 'Lomba Cerdas Cermat', 'level' => 'Kabupaten'],
        ['name' => 'Lomba Karya Tulis Ilmiah', 'level' => 'Provinsi'],
        ['name' => 'Lomba Pidato Bahasa Inggris', 'level' => 'Kabupaten'],
        ['name' => 'Lomba Desain Grafis', 'level' => 'Kecamatan'],
        ['name' => 'Lomba Tahfidz Al-Quran', 'level' => 'Kabupaten'],
        ['name' => 'Paskibraka', 'level' => 'Kabupaten'],
        ['name' => 'Juara Kelas (Peringkat Pararel)', 'level' => 'Sekolah'],
        ['name' => 'Siswa Berprestasi', 'level' => 'Sekolah'],
        ['name' => 'Prestasi Non-Akademik Lainnya', 'level' => 'Sekolah'],
    ];

    public function run(): void
    {
        foreach ($this->types as $row) {
            AchievementType::firstOrCreate(
                ['name' => $row['name']],
                ['level' => $row['level']],
            );
        }
    }
}
