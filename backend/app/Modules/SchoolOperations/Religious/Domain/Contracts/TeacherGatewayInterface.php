<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Guru dan kualifikasi keagamaan (Academic/HRD).
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface TeacherGatewayInterface
{
    /** Ringkasan guru. */
    public function snapshot(int $teacherId): ?array;

    /** Guru dengan spesialisasi keagamaan. */
    public function religiousSpecialists(array $filters = []): array;

    /** Ketersediaan sumber guru. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
