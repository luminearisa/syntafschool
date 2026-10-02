<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Facility/Fasilitas (§59, §60). Pemesanan ruang masjid memakai modul ini.
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface FacilityGatewayInterface
{
    /** Data fasilitas. */
    public function find(int $facilityId): ?array;

    /** Ajukan pemesanan fasilitas. */
    public function requestBooking(array $payload): array;

    /** Ketersediaan fasilitas pada rentang waktu. */
    public function availability(array $filters): array;

    /** Ketersediaan modul fasilitas. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
