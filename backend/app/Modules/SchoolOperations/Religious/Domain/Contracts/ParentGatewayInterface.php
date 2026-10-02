<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Wali murid. Dipakai untuk dashboard wali dan isolasi data (§14, §68).
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface ParentGatewayInterface
{
    /** Daftar anak yang menjadi tanggung jawab wali. */
    public function childrenFor(int $parentUserId): array;

    /** Daftar wali dari seorang siswa. */
    public function guardiansFor(int $studentId): array;

    /** Apakah wali berhak melihat data siswa tersebut. */
    public function canAccess(int $parentUserId, int $studentId): bool;

    /** Ketersediaan sumber data wali. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
