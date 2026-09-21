<?php

namespace Database\Seeders;

use App\Models\Program;
use Illuminate\Database\Seeder;

/**
 * Program / peminatan SMA.
 *
 * `education_level_id` dibiarkan null karena program berlaku lintas tingkat
 * (Kelas 10–12), bukan terikat pada satu tingkat tertentu.
 */
class ProgramSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name: string, description: string}>
     */
    protected array $programs = [
        ['code' => 'IPA', 'name' => 'Ilmu Pengetahuan Alam', 'description' => 'Peminatan Matematika dan Ilmu Alam.'],
        ['code' => 'IPS', 'name' => 'Ilmu Pengetahuan Sosial', 'description' => 'Peminatan Ilmu Sosial.'],
        ['code' => 'BAHASA', 'name' => 'Bahasa', 'description' => 'Peminatan Bahasa dan Budaya.'],
    ];

    public function run(): void
    {
        foreach ($this->programs as $program) {
            Program::updateOrCreate(
                ['code' => $program['code']],
                [
                    'name' => $program['name'],
                    'description' => $program['description'],
                    'education_level_id' => null,
                    'is_active' => true,
                ],
            );
        }
    }
}
