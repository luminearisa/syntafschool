<?php

namespace App\Modules\Activity\Domain\Contracts;

/**
 * Asrama — kegiatan wajib, kurfew, dan jadwal asrama (§59, §98).
 *
 * Deteksi bentrok memerlukan jadwal asrama; modul ini tidak menyimpan jadwal
 * asrama dan tidak mengubahnya.
 */
interface BoardingProviderInterface
{
    /** Kewajiban asrama siswa pada satu tanggal (dengan jam, bila ada). */
    public function obligationsFor(int $studentId, string $date): array;

    /** Penempatan asrama siswa (dormitory/kamar) untuk laporan dan portal. */
    public function placementsFor(int $studentId): array;

    /** Kegiatan asrama yang berulang tiap pekan beserta jamnya. */
    public function weeklyObligations(): array;
}
