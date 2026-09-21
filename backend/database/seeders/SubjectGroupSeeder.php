<?php

namespace Database\Seeders;

use App\Models\SubjectGroup;
use Illuminate\Database\Seeder;

/**
 * Kelompok mata pelajaran untuk Kurikulum Merdeka SMA.
 *
 * Idempoten: `updateOrCreate` pada `code`.
 */
class SubjectGroupSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name: string, sort_order: int}>
     */
    protected array $groups = [
        ['code' => 'WAJIB', 'name' => 'Wajib', 'sort_order' => 1],
        ['code' => 'MIPA', 'name' => 'MIPA', 'sort_order' => 2],
        ['code' => 'IPS', 'name' => 'IPS', 'sort_order' => 3],
        ['code' => 'BAHASA', 'name' => 'Bahasa', 'sort_order' => 4],
        ['code' => 'KEAGAMAAN', 'name' => 'Keagamaan', 'sort_order' => 5],
        ['code' => 'PILIHAN', 'name' => 'Pilihan', 'sort_order' => 6],
        ['code' => 'MULOK', 'name' => 'Muatan Lokal', 'sort_order' => 7],
    ];

    public function run(): void
    {
        foreach ($this->groups as $group) {
            SubjectGroup::updateOrCreate(
                ['code' => $group['code']],
                [
                    'name' => $group['name'],
                    'sort_order' => $group['sort_order'],
                    'is_active' => true,
                ],
            );
        }
    }
}
