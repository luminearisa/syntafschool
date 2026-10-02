<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Siswa (Academic/Student module). Modul ini tidak menyimpan master siswa.
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface StudentGatewayInterface
{
    /** Ringkasan siswa: nama, NIS, kelas, tingkat, kampus. */
    public function snapshot(int $studentId): ?array;

    /** Ringkasan banyak siswa sekaligus. */
    public function snapshots(array $studentIds): array;

    /** Pencarian siswa untuk pemilihan peserta. */
    public function search(string $term, int $limit = 20): array;

    /** Status aktif siswa. */
    public function isActive(int $studentId): bool;

    /** Apakah sumber siswa tersedia pada instalasi ini. */
    public function available(): bool;

    /** Alasan bila tidak tersedia (untuk pesan ke pengguna). */
    public function unavailableReason(): ?string;
}
