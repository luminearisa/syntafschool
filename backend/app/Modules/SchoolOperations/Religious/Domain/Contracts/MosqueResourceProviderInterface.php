<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Ketersediaan masjid, area, dan pemesanan untuk modul lain (Event, Facility, Boarding).
 *
 * Kontrak yang DISEDIAKAN modul Religious untuk modul lain.
 */
interface MosqueResourceProviderInterface
{
    /** Daftar masjid. */
    public function mosques(array $filters = []): array;

    /** Daftar area dalam masjid. */
    public function areas(int $mosqueId, array $filters = []): array;

    /** Ketersediaan masjid/area pada rentang waktu. */
    public function isAvailable(int $mosqueId, ?int $areaId, string $startAt, string $endAt): bool;

    /** Daftar pemesanan. */
    public function bookings(array $filters = []): array;
}
