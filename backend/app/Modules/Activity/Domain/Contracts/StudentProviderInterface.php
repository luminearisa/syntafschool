<?php

namespace App\Modules\Activity\Domain\Contracts;

/**
 * Identitas siswa — **source of truth tetap modul Student**.
 *
 * Modul Activity hanya menyimpan `student_id`. Nama, kelas, dan program selalu
 * dibaca dari sini, sehingga tidak ada penyalinan identitas yang bisa basi.
 */
interface StudentProviderInterface
{
    /** Potret ringkas siswa; `null` bila siswa tidak ada/tidak aktif. */
    public function snapshot(int $studentId): ?array;

    /** @return list<array> pencarian siswa untuk layar admin/pembina */
    public function search(string $term, int $limit = 20): array;

    /** Kelas siswa saat ini (untuk aturan jenjang & laporan per kelas). */
    public function currentClass(int $studentId): ?array;

    /** @param list<int> $studentIds @return array<int, array> */
    public function snapshots(array $studentIds): array;
}
