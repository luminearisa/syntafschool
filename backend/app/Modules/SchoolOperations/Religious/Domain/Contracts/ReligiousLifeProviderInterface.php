<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Kontrak yang disediakan modul Religious untuk modul lain: jadwal shalat, kegiatan, dan hari keagamaan.
 *
 * Kontrak yang DISEDIAKAN modul Religious untuk modul lain.
 */
interface ReligiousLifeProviderInterface
{
    /** Jadwal shalat satu hari beserta imam/muadzin bertugas. */
    public function prayerTimesFor(string $date, ?int $mosqueId = null): array;

    /** Shalat berikutnya pada waktu tertentu. */
    public function nextPrayer(?int $mosqueId = null, ?string $at = null): ?array;

    /** Kegiatan keagamaan hari ini. */
    public function todayActivities(?int $mosqueId = null, ?string $date = null): array;

    /** Apakah tanggal tersebut berada dalam program Ramadan aktif. */
    public function isRamadan(?string $date = null): bool;

    /** Daftar masjid pada kampus tertentu. */
    public function mosquesFor(?int $campusId = null): array;

    /** Ketersediaan modul Religious pada instalasi ini. */
    public function available(): bool;
}
