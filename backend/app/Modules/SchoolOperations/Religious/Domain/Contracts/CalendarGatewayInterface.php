<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Kalender institusi (§85). Semua jadwal keagamaan dipublikasikan ke sini.
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface CalendarGatewayInterface
{
    /** Publikasikan satu entri kalender. */
    public function publish(array $entry): array;

    /** Publikasikan banyak entri kalender. */
    public function publishMany(array $entries): array;

    /** Hapus entri kalender berdasarkan referensi. */
    public function remove(string $reference): bool;

    /** Ketersediaan kalender. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
