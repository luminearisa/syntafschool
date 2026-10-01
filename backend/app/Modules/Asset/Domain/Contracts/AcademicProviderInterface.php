<?php

namespace App\Modules\Asset\Domain\Contracts;

/**
 * Akademik — dipakai agar booking fasilitas tidak menabrak jadwal pelajaran.
 *
 * Arah pemeriksaan satu arah: Asset bertanya ke Akademik, bukan sebaliknya.
 */
interface AcademicProviderInterface
{
    /** Apakah ruang/fasilitas terpakai jadwal akademik pada rentang itu? */
    public function roomScheduleConflicts(int $locationId, string $from, string $to): array;

    /** Jadwal akademik pada ruang tersebut untuk ditampilkan di halaman fasilitas. */
    public function roomSchedule(int $locationId, string $date): array;
}
