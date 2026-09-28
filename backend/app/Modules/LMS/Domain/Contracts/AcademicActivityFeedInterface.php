<?php

namespace App\Modules\LMS\Domain\Contracts;

/**
 * Umpan aktivitas akademik ERP (tugas, pengumpulan, nilai) untuk dashboard.
 *
 * Dipisahkan sebagai kontrak supaya `LmsDashboardService` tidak menyentuh model
 * Assignment/Score secara langsung: pada Integrated Mode datanya berasal dari
 * modul akademik ERP, pada Standalone Mode umpan ini kosong dan dashboard
 * mengandalkan aktivitas LMS sendiri. Phase 2 (Assignment & Quiz LMS) akan
 * memakai kontrak yang sama.
 */
interface AcademicActivityFeedInterface
{
    /**
     * Tugas ERP yang akan datang untuk seorang siswa.
     *
     * @return list<array<string, mixed>>
     */
    public function upcomingForStudent(int $studentId, int $limit = 5): array;

    /**
     * Pengumpulan yang menunggu penilaian seorang guru (§27).
     *
     * @return list<array<string, mixed>>
     */
    public function pendingGrading(?int $employeeId, int $limit = 10): array;

    /** @return list<array<string, mixed>> */
    public function recentSubmissions(?int $employeeId, int $limit = 10): array;

    /**
     * Tugas ERP yang akan datang untuk sekumpulan rombel.
     *
     * @param  list<int>  $classIds
     * @return list<array<string, mixed>>
     */
    public function upcomingForClasses(array $classIds, int $limit = 5): array;

    /**
     * Ringkasan nilai ERP seorang siswa (§3 policy wali).
     *
     * @return array<string, mixed>
     */
    public function gradesForStudent(int $studentId): array;

    /**
     * Jumlah pengumpulan yang menunggu penilaian.
     *
     * @return array<string, int>
     */
    public function gradingCounters(?int $employeeId): array;
}
