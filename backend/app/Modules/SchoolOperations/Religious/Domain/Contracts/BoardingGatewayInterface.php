<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Asrama/pesantren. Wajib untuk presensi shalat berjamaah santri (§24, §69).
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface BoardingGatewayInterface
{
    /** Apakah siswa berstatus penghuni asrama. */
    public function isResident(int $studentId, ?string $onDate = null): bool;

    /** Daftar kamar/asrama beserta kapasitas. */
    public function dormitories(array $filters = []): array;

    /** Penghuni satu asrama. */
    public function residentsOf(int $dormitoryId, array $filters = []): array;

    /** Kewajiban asrama pada tanggal tertentu. */
    public function obligationsFor(int $studentId, string $date): array;

    /** Ketersediaan modul asrama. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
