<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Tahfidz & Halaqah (§33, §42, §43, §83). Modul Tahfidz tetap sumber kebenarannya.
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface TahfidzGatewayInterface
{
    /** Halaqah yang diikuti siswa. */
    public function halaqahFor(int $studentId): array;

    /** Ringkasan progres hafalan (juz, halaman, tingkat). */
    public function progressFor(int $studentId): array;

    /** Sesi halaqah pada tanggal tertentu. */
    public function sessionsFor(?int $halaqahId, string $date): array;

    /** Meneruskan presensi ke modul Tahfidz, bila relevan. */
    public function recordAttendance(array $payload): array;

    /** Ketersediaan modul Tahfidz. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
