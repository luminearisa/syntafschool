<?php

namespace Database\Seeders;

use App\Models\Library;
use App\Models\LibraryLocation;
use App\Models\School;
use App\Services\Library\LibraryLocationService;
use App\Services\Library\LibraryService;
use Illuminate\Database\Seeder;

/**
 * Data contoh modul Library (Step 1).
 *
 * Sengaja TIDAK mengisi buku, anggota, atau transaksi — entity tersebut
 * belum ada pada Step 1. Yang dibuat hanya perpustakaan beserta konfigurasi
 * minimumnya (jam operasional + lokasi) agar dashboard & checklist dapat
 * dilihat langsung.
 */
class LibrarySeeder extends Seeder
{
    public function run(): void
    {
        if (Library::query()->where('code', 'LIB-MAIN')->exists()) {
            return;
        }

        /** @var LibraryService $libraries */
        $libraries = app(LibraryService::class);
        /** @var LibraryLocationService $locations */
        $locations = app(LibraryLocationService::class);

        $school = School::query()->orderBy('id')->first();

        $library = $libraries->create([
            'school_id' => $school?->id,
            'code' => 'LIB-MAIN',
            'name' => 'Perpustakaan Utama',
            'description' => 'Perpustakaan utama sekolah.',
            'timezone' => 'Asia/Jakarta',
            'status' => Library::STATUS_DRAFT,
        ]);

        // Jam operasional: Senin–Jumat 08:00–16:00, Sabtu 08:00–12:00, Ahad tutup.
        $hours = [];

        foreach (range(0, 6) as $day) {
            $isOpen = $day >= 1;

            $hours[] = [
                'day_of_week' => $day,
                'is_open' => $isOpen,
                'open_time' => $isOpen ? '08:00' : null,
                'close_time' => $isOpen ? ($day === 6 ? '12:00' : '16:00') : null,
            ];
        }

        $libraries->syncOperatingHours($library, $hours);

        // Hierarki lokasi: Lantai → ruang.
        $lantai1 = $locations->create($library, [
            'code' => 'L1',
            'name' => 'Lantai 1',
            'type' => LibraryLocation::TYPE_FLOOR,
        ]);

        $lantai2 = $locations->create($library, [
            'code' => 'L2',
            'name' => 'Lantai 2',
            'type' => LibraryLocation::TYPE_FLOOR,
        ]);

        $locations->create($library, [
            'code' => 'RB',
            'name' => 'Ruang Baca',
            'type' => LibraryLocation::TYPE_READING_AREA,
            'parent_id' => $lantai1->id,
        ]);

        $locations->create($library, [
            'code' => 'SD',
            'name' => 'Service Desk',
            'type' => LibraryLocation::TYPE_SERVICE_DESK,
            'parent_id' => $lantai1->id,
        ]);

        $locations->create($library, [
            'code' => 'RR',
            'name' => 'Ruang Referensi',
            'type' => LibraryLocation::TYPE_ROOM,
            'parent_id' => $lantai2->id,
        ]);

        $locations->create($library, [
            'code' => 'RM',
            'name' => 'Ruang Multimedia',
            'type' => LibraryLocation::TYPE_ROOM,
            'parent_id' => $lantai2->id,
        ]);

        // Konfigurasi minimum sudah lengkap → aktifkan (juga menghasilkan
        // satu jejak audit LIBRARY_STATUS_CHANGED untuk diperiksa).
        $libraries->transition($library, Library::STATUS_ACTIVE, 'Aktivasi awal melalui seeder.');
    }
}