<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Student Activity: partisipasi, prestasi, dan portofolio (§54, §55, §56, §82).
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface StudentActivityGatewayInterface
{
    /** Catat partisipasi siswa pada kegiatan. */
    public function recordParticipation(array $payload): array;

    /** Teruskan prestasi ke modul prestasi siswa. */
    public function recordAchievement(array $payload): array;

    /** Portofolio kegiatan siswa. */
    public function portfolioFor(int $studentId): array;

    /** Ketersediaan modul aktivitas siswa. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
