<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Penyedia waktu shalat (§12). Sistem TIDAK terkunci pada satu sumber jadwal.
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface PrayerTimeProviderInterface
{
    /** Jadwal shalat satu hari: adhan, iqamah, mulai, selesai. */
    public function scheduleFor(string $date, array $options = []): array;

    /** Jadwal shalat beberapa hari. */
    public function rangeFor(string $startDate, string $endDate, array $options = []): array;

    /** Kemampuan penyedia (sumber, presisi, dukungan Ramadan). */
    public function capabilities(): array;

    /** Kode penyedia: MANUAL, CONFIGURATION, CALCULATION, EXTERNAL_API. */
    public function providerCode(): string;

    /** Ketersediaan penyedia. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
