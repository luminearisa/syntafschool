<?php

namespace App\Modules\Asset\Infrastructure\Providers;

use App\Modules\Asset\Domain\Contracts\AcademicProviderInterface;

/**
 * Penyedia Akademik kosong (bawaan).
 *
 * Dipakai selama sekolah belum memetakan ruangnya (`locations.academic_room_id`).
 * Dinyatakan terus terang di sini supaya tidak ada yang mengira pemeriksaan
 * benturan jadwal pelajaran "sudah berjalan" padahal pemetaannya belum diisi.
 */
class NullAcademicProvider implements AcademicProviderInterface
{
    public function roomScheduleConflicts(int $locationId, string $from, string $to): array
    {
        return [];
    }

    public function roomSchedule(int $locationId, string $date): array
    {
        return [];
    }
}
