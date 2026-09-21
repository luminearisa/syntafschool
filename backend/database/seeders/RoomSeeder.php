<?php

namespace Database\Seeders;

use App\Models\Room;
use Illuminate\Database\Seeder;

/**
 * Ruangan sekolah: ruang kelas, laboratorium, perpustakaan, dan kantor.
 */
class RoomSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name: string, type: string, capacity: int, building: string}>
     */
    protected array $rooms = [
        ['code' => 'R-101', 'name' => 'Ruang X IPA 1', 'type' => 'Kelas', 'capacity' => 32, 'building' => 'Gedung A'],
        ['code' => 'R-102', 'name' => 'Ruang X IPA 2', 'type' => 'Kelas', 'capacity' => 32, 'building' => 'Gedung A'],
        ['code' => 'R-201', 'name' => 'Ruang XI IPA 1', 'type' => 'Kelas', 'capacity' => 32, 'building' => 'Gedung A'],
        ['code' => 'R-202', 'name' => 'Ruang XI IPA 2', 'type' => 'Kelas', 'capacity' => 32, 'building' => 'Gedung A'],
        ['code' => 'R-301', 'name' => 'Ruang XII IPA 1', 'type' => 'Kelas', 'capacity' => 32, 'building' => 'Gedung A'],
        ['code' => 'R-302', 'name' => 'Ruang XII IPS 1', 'type' => 'Kelas', 'capacity' => 32, 'building' => 'Gedung A'],
        ['code' => 'LAB-FIS', 'name' => 'Laboratorium Fisika', 'type' => 'Lab', 'capacity' => 30, 'building' => 'Gedung B'],
        ['code' => 'LAB-KIM', 'name' => 'Laboratorium Kimia', 'type' => 'Lab', 'capacity' => 30, 'building' => 'Gedung B'],
        ['code' => 'LAB-BIO', 'name' => 'Laboratorium Biologi', 'type' => 'Lab', 'capacity' => 30, 'building' => 'Gedung B'],
        ['code' => 'LAB-KOM', 'name' => 'Laboratorium Komputer', 'type' => 'Lab', 'capacity' => 36, 'building' => 'Gedung B'],
        ['code' => 'PERPUS', 'name' => 'Perpustakaan', 'type' => 'Perpustakaan', 'capacity' => 60, 'building' => 'Gedung C'],
        ['code' => 'KANTOR-TU', 'name' => 'Kantor Tata Usaha', 'type' => 'Kantor', 'capacity' => 10, 'building' => 'Gedung C'],
        ['code' => 'KANTOR-KS', 'name' => 'Ruang Kepala Sekolah', 'type' => 'Kantor', 'capacity' => 4, 'building' => 'Gedung C'],
        ['code' => 'AULA', 'name' => 'Aula Serbaguna', 'type' => 'Lainnya', 'capacity' => 200, 'building' => 'Gedung C'],
    ];

    public function run(): void
    {
        foreach ($this->rooms as $row) {
            Room::firstOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'capacity' => $row['capacity'],
                    'building' => $row['building'],
                    'is_active' => true,
                ],
            );
        }
    }
}
