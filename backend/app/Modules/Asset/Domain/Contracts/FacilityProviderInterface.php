<?php

namespace App\Modules\Asset\Domain\Contracts;

/**
 * Fasilitas — dipakai Akademik, Kegiatan, dan Event untuk memesan ruang.
 *
 * Pemeriksaan benturan waktu tetap di modul ini: pemakal kontrak tidak boleh
 * "mengetahui" jadwal fasilitas, cukup menerima jawaban boleh/tidak beserta
 * alasannya.
 */
interface FacilityProviderInterface
{
    public function info(int $facilityId): ?array;

    /** Ketersediaan fasilitas pada rentang waktu; mengembalikan slot + alasan penolakan. */
    public function availability(int $facilityId, string $from, string $to): array;

    /** Ajukan booking; melempar pengecualian bila bentrok atau fasilitas ditutup. */
    public function book(array $payload): array;

    /** Tutup fasilitas sementara dan kembalikan daftar booking yang terdampak. */
    public function close(array $payload): array;

    public function reopen(int $facilityId, ?string $reason = null): array;
}
